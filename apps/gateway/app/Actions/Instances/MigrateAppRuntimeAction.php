<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\AppDev\AnnotatorEndpoint;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\AppRuntimeMigrationProjector;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\VitePortRuntime;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessRuntimeLease;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Shared\StoredInteger;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\AppRuntimeMigration;
use App\Models\Instance;
use App\Models\InstanceRename;
use App\Models\InstanceTransfer;
use App\Models\Node;
use App\Models\Process;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** @phpstan-import-type MigrationPlan from AppRuntimeMigration
 * @phpstan-import-type PortOwner from AppRuntimeMigration
 */
final readonly class MigrateAppRuntimeAction
{
    public function __construct(
        private AppDevSourceOperationLock $source,
        private DevelopmentProjectionOperationLock $projection,
        private InstanceEnvironmentOperationLock $environment,
        private ProcessAdmissionLock $admissions,
        private ProcessRuntimeLease $leases,
        private VitePortRuntime $ports,
        private AppRuntimeMigrationProjector $projector,
    ) {}

    public function execute(Node $node): void
    {
        if (! $this->hasWork($node)) {
            return;
        }
        $ids = Instance::query()->where('node_id', $node->id)->orderBy('id')->get()->map(static fn (Instance $instance): int => $instance->id)->values()->all();
        $ids = array_values($ids);
        $this->withEnvironments($ids, fn () => $this->admissions->run($ids, fn () => $this->source->synchronized($node->id, fn () => $this->projection->run(function () use ($node, $ids): void {
            InstanceRename::assertAvailable($ids);
            $migration = AppRuntimeMigration::query()->where('node_id', $node->id)->where('phase', '!=', 'complete')->first();
            if (! $migration instanceof AppRuntimeMigration) {
                $plan = $this->plan($node);
                if ($plan['stores'] === [] && ($plan['vite_files'] ?? []) === [] && ! array_any($plan['owners'], static fn (array $owner): bool => $owner['old_port'] !== $owner['new_port'])) {
                    return;
                }
                $migration = AppRuntimeMigration::query()->create(['id' => (string) Str::uuid(), 'node_id' => $node->id, 'phase' => 'reserved', 'plan' => $plan]);
            }
            $this->withProcesses(array_column($migration->plan['processes'], 'id'), fn () => $this->resume($node, $migration));
        }))));
    }

    /** @template T
     * @param  list<int>  $ids
     * @param  Closure():T  $operation
     * @return T
     */
    private function withEnvironments(array $ids, Closure $operation): mixed
    {
        return $this->environment->run($ids, $operation);
    }

    /** @template T
     * @param  list<int>  $ids
     * @param  Closure():T  $operation
     * @return T
     */
    private function withProcesses(array $ids, Closure $operation): mixed
    {
        $id = array_shift($ids);

        return $id === null ? $operation() : $this->leases->run(Process::query()->findOrFail($id), fn () => $this->withProcesses($ids, $operation));
    }

    private function resume(Node $node, AppRuntimeMigration $migration): void
    {
        if ($migration->published_at !== null) {
            $this->projector->cleanup($node, $migration);
            $migration->update(['phase' => 'complete']);

            return;
        }
        if ($migration->phase === 'rollback_required') {
            $this->projector->rollback($node, $migration);
            $migration->update(['phase' => 'reserved']);
        }
        try {
            if ($migration->phase === 'reserved') {
                $observations = $this->projector->prepare($node, $migration);
                $migration->update(['observations' => $observations, 'phase' => 'prepared']);
            }
            $migration->update(['phase' => 'activating']);
            $this->projector->activate($node, $migration);
            DB::transaction(function () use ($migration): void {
                foreach ($migration->plan['owners'] as $owner) {
                    if ($owner['new_port'] === $owner['old_port']) {
                        continue;
                    }
                    $table = $owner['kind'] === 'vite_port' ? 'vite_port_assignments' : 'annotation_port_assignments';
                    $query = DB::table($table)->where('node_id', $owner['node_id'])->where('instance_id', $owner['instance_id'])->where('app', $owner['app']);
                    if ($table === 'annotation_port_assignments') {
                        $query->where('kind', $owner['kind']);
                    }
                    if ($query->where('port', $owner['old_port'])->update(['port' => $owner['new_port']]) !== 1) {
                        throw new ResourceOperationException('instance.lifecycle_conflict', 'A migration reservation changed before publication.', 409);
                    }
                    $instance = Instance::query()->findOrFail($owner['instance_id']);
                    if ($instance->node_id !== $owner['node_id']) {
                        throw new ResourceOperationException('instance.lifecycle_conflict', 'A migration placement changed before publication.', 409);
                    }
                    $instance->recordAppRuntime($owner['app'], [$owner['kind'] => $owner['new_port']]);
                    if (count($instance->effectiveApps()) === 1) {
                        $instance->update([$owner['kind'] => $owner['new_port']]);
                    }
                }
                foreach ($migration->plan['vite_files'] ?? [] as $file) {
                    Instance::query()->findOrFail($file['instance_id'])->recordAppRuntime($file['app'], ['vite_environment_identity' => true]);
                }
                foreach ($migration->plan['stores'] as $store) {
                    $instance = Instance::query()->findOrFail($store['instance_id']);
                    if ($instance->node_id !== $migration->node_id) {
                        throw new ResourceOperationException('instance.lifecycle_conflict', 'A store placement changed before publication.', 409);
                    }
                    $instance->recordAppRuntime($store['app'], ['annotator_store_identity' => true]);
                }
                $migration->update(['published_at' => now(), 'phase' => 'published']);
            });
        } catch (\Throwable $error) {
            // Re-read the durable receipt: a lost response must never undo publication.
            $migration->refresh();
            if ($migration->published_at === null) {
                $migration->update(['phase' => 'rollback_required']);
                $this->projector->rollback($node, $migration);
                $migration->update(['phase' => 'reserved']);
            }
            throw $error;
        }
        $this->projector->cleanup($node, $migration);
        $migration->update(['phase' => 'complete']);
    }

    private function hasWork(Node $node): bool
    {
        if (AppRuntimeMigration::query()->where('node_id', $node->id)->where('phase', '!=', 'complete')->exists()) {
            return true;
        }
        $ports = [...DB::table('vite_port_assignments')->where('node_id', $node->id)->pluck('port')->all(), ...DB::table('annotation_port_assignments')->where('node_id', $node->id)->pluck('port')->all()];
        $ports = array_map(StoredInteger::from(...), $ports);
        if (count(array_unique($ports)) !== count($ports)) {
            return true;
        }
        foreach (Instance::query()->with('project')->where('node_id', $node->id)->get() as $instance) {
            if (InstanceTransfer::query()->where('instance_id', $instance->id)->whereNotIn('id', InstanceTransfer::query()->closed()->select('id'))->exists()) {
                continue;
            }
            foreach ($instance->effectiveApps() as $app) {
                if (! $instance->usesAppViteIdentity($app['name']) && $instance->processes()->where('app', $app['name'])->where('runtime_config->preset', 'vp-dev')->whereNull('endpoint_withdrawal_started_at')->exists()) {
                    return true;
                }
                if (! $instance->usesAppStoreIdentity($app['name']) && is_int($instance->runtimeForApp($app['name'])['annotator_port']) && ! $instance->processes()->where('app', $app['name'])->whereNotNull('endpoint_withdrawal_started_at')->exists()) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return MigrationPlan */
    private function plan(Node $node): array
    {
        $owners = [];
        foreach (['vite_port_assignments', 'annotation_port_assignments'] as $table) {
            foreach (DB::table($table)->where('node_id', $node->id)->get() as $row) {
                $kind = $table === 'vite_port_assignments' ? 'vite_port' : $row->kind;
                $instance = Instance::query()->findOrFail(StoredInteger::from($row->instance_id));
                if (! is_string($row->app)) {
                    throw new ResourceOperationException('app.not_found', 'A reservation has no frozen app ownership.', 409);
                }
                $instance->appConfiguration($row->app);
                $operations = InstanceTransfer::query()->where('instance_id', $instance->id)->whereNotIn('id', InstanceTransfer::query()->closed()->select('id'))->get()->map(static fn (InstanceTransfer $transfer): string => $transfer->id)->values()->all();
                $preset = match ($kind) {
                    'vite_port' => 'vp-dev', 'agentation_port' => 'agentation-mcp', 'annotator_port' => 'annotator', default => throw new \LogicException('Invalid endpoint kind.')
                };
                foreach ($instance->processes()->where('app', $row->app)->where('runtime_config->preset', $preset)->whereNotNull('endpoint_withdrawal_started_at')->pluck('id') as $id) {
                    $operations[] = 'process:'.StoredInteger::from($id);
                }
                $owners[] = ['instance_id' => $instance->id, 'node_id' => $node->id, 'app' => $row->app, 'kind' => $kind, 'old_port' => StoredInteger::from($row->port), 'new_port' => StoredInteger::from($row->port), 'retained' => $operations !== [] || $instance->node_id !== $node->id, 'operations' => array_values($operations)];
            }
        }
        $priority = ['vite_port' => 0, 'agentation_port' => 1, 'annotator_port' => 2];
        usort($owners, static fn (array $a, array $b): int => [! $a['retained'], $priority[$a['kind']], $a['instance_id'], $a['app']] <=> [! $b['retained'], $priority[$b['kind']], $b['instance_id'], $b['app']]);
        $claimed = [];
        $excluded = [...array_column($owners, 'old_port'), 22, 53, 80, 443, 2019, 2375, 2376, 3306, 5432, 6379, 9000, 9100, 9187];
        foreach ($owners as &$owner) {
            if (isset($claimed[$owner['old_port']])) {
                if ($owner['retained']) {
                    throw new ResourceOperationException('app.port_migration_conflict', 'Retained endpoint reservations collide; complete their owning operations before retrying.', 409, details: ['node_id' => (string) $node->id, 'owners' => json_encode([$claimed[$owner['old_port']], $owner], JSON_THROW_ON_ERROR)]);
                }
                $preferred = match ($owner['kind']) {
                    'vite_port' => 5173, 'agentation_port' => 4747, 'annotator_port' => 4848
                };
                $owner['new_port'] = $this->ports->selectPort($node, $preferred, $excluded);
                $excluded[] = $owner['new_port'];
            }
            $claimed[$owner['new_port']] = $owner;
        }
        unset($owner);
        $stores = [];
        $viteFiles = [];
        foreach (Instance::query()->with('project')->where('node_id', $node->id)->orderBy('id')->get() as $instance) {
            foreach ($instance->effectiveApps() as $app) {
                $key = $app['name'];
                $pending = InstanceTransfer::query()->where('instance_id', $instance->id)->whereNotIn('id', InstanceTransfer::query()->closed()->select('id'))->exists()
                    || $instance->processes()->where('app', $key)->whereNotNull('endpoint_withdrawal_started_at')->exists();
                if (! $pending && ! $instance->usesAppViteIdentity($key) && $instance->processes()->where('app', $key)->where('runtime_config->preset', 'vp-dev')->exists()) {
                    $viteFiles[] = ['instance_id' => $instance->id, 'app' => $key,
                        'old' => SystemdProcessRenderer::viteEnvironmentPath($instance->id),
                        'new' => SystemdProcessRenderer::viteEnvironmentPath($instance->id, $key)];
                }
                if ($instance->usesAppStoreIdentity($key) || ! is_int($instance->runtimeForApp($key)['annotator_port'])) {
                    continue;
                }
                if (InstanceTransfer::query()->where('instance_id', $instance->id)->whereNotIn('id', InstanceTransfer::query()->closed()->select('id'))->exists() || $instance->processes()->where('app', $key)->whereNotNull('endpoint_withdrawal_started_at')->exists()) {
                    continue; // Keep the original operation's exact store path until it closes.
                }
                $stores[] = ['instance_id' => $instance->id, 'app' => $key, 'old' => AnnotatorEndpoint::store($instance->id), 'new' => AnnotatorEndpoint::store($instance->id, $key)];
            }
        }
        $affected = [];
        foreach ($owners as $owner) {
            if ($owner['new_port'] !== $owner['old_port']) {
                $affected[$owner['instance_id']][$owner['app']] = true;
            }
        }
        foreach ($viteFiles as $file) {
            $affected[$file['instance_id']][$file['app']] = true;
        }
        foreach ($stores as $store) {
            $affected[$store['instance_id']][$store['app']] = true;
        }
        $processes = [];
        foreach (Process::query()->where('owner_type', Instance::MorphAlias)->where('runtime', 'systemd')->whereNull('endpoint_withdrawal_started_at')->orderBy('id')->get() as $process) {
            if ($process->app === null) {
                if (isset($affected[$process->owner_id])) {
                    throw new ResourceOperationException('app.not_found', 'An affected Process has no app ownership.', 409);
                }

                continue;
            }
            if (isset($affected[$process->owner_id][$process->app])) {
                $processes[] = ['id' => $process->id, 'app' => $process->app, 'fingerprint' => self::fingerprint($process)];
            }
        }

        return ['owners' => $owners, 'stores' => $stores, 'vite_files' => $viteFiles, 'processes' => $processes];
    }

    public static function candidate(Instance $instance, AppRuntimeMigration $migration): Instance
    {
        $candidate = clone $instance;
        $runtime = $candidate->app_runtime ?? [];
        foreach ($migration->plan['owners'] as $owner) {
            if ($owner['instance_id'] === $candidate->id && $owner['node_id'] === $candidate->node_id) {
                $runtime[$owner['app']] = [...($runtime[$owner['app']] ?? []), ...match ($owner['kind']) {
                    'vite_port' => ['vite_port' => $owner['new_port']],
                    'agentation_port' => ['agentation_port' => $owner['new_port']],
                    'annotator_port' => ['annotator_port' => $owner['new_port']],
                    default => throw new \LogicException('Invalid endpoint kind.'),
                }];
            }
        }
        foreach ($migration->plan['vite_files'] ?? [] as $file) {
            if ($file['instance_id'] === $candidate->id) {
                $runtime[$file['app']]['vite_environment_identity'] = true;
            }
        }
        foreach ($migration->plan['stores'] as $store) {
            if ($store['instance_id'] === $candidate->id) {
                $runtime[$store['app']]['annotator_store_identity'] = true;
            }
        }
        $candidate->app_runtime = $runtime;

        return $candidate;
    }

    public static function fingerprint(Process $process): string
    {
        return hash('sha256', json_encode([$process->id, $process->owner_type, $process->owner_id, $process->app, $process->name, $process->working_directory, $process->runtime_config], JSON_THROW_ON_ERROR));
    }
}
