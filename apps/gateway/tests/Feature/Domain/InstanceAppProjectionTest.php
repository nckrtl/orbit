<?php

declare(strict_types=1);

use App\Actions\Instances\AdmitInstanceAppMutationAction;
use App\Actions\Instances\Dependencies\ScanInstanceDependenciesAction;
use App\Actions\Instances\Dependencies\UpdateInstanceDependenciesAction;
use App\Actions\Instances\DeployInstanceAction;
use App\Actions\Instances\ImportInstanceEnvironmentAction;
use App\Actions\Instances\RemoveInstanceAction;
use App\Actions\Instances\RenameInstanceAction;
use App\Actions\Instances\ReserveInstanceAppProjectionsAction;
use App\Actions\Instances\RollbackInstanceAction;
use App\Actions\Instances\RunInstanceAppProjectionAction;
use App\Actions\Instances\SynchronizeInstanceEnvironmentAction;
use App\Actions\Instances\TransferInstanceAction;
use App\Actions\Instances\UpdateInstanceEnvironmentAction;
use App\Actions\Processes\AddProcessAction;
use App\Actions\Processes\RemoveProcessAction;
use App\Actions\Processes\RestartProcessAction;
use App\Actions\Processes\StartProcessAction;
use App\Actions\Processes\StopProcessAction;
use App\Actions\Schedules\ActivateScheduleAction;
use App\Actions\Schedules\AddScheduleAction;
use App\Actions\Schedules\RemoveScheduleAction;
use App\Actions\Schedules\RunScheduleAction;
use App\Actions\Schedules\ShowScheduleLogsAction;
use App\Data\Instances\RenameInstanceData;
use App\Data\Instances\TransferInstanceData;
use App\Data\Processes\AddProcessData;
use App\Data\Schedules\AddScheduleData;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Doctor\ScheduleInspectionData;
use App\Domain\Instances\Apps\AppProjectionIdentity;
use App\Domain\Instances\Apps\AppProjectionPlan;
use App\Domain\Instances\Apps\AppProjectionReceipt;
use App\Domain\Instances\Apps\AppProjectionStepAdapter;
use App\Domain\Instances\Apps\AppProjectionStepPlan;
use App\Domain\Instances\Deployment\DeploymentResult;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTargetType;
use App\Domain\Projects\ProjectUpdateStatus;
use App\Domain\Schedules\ScheduleRenderer;
use App\Domain\Schedules\ScheduleTargetResolver;
use App\Domain\Schedules\ScheduleTargetType;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Doctor\NativeScheduleStateInspector;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Schedules\RemoteScheduleRuntimeManager;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\InstanceAppProjection;
use App\Models\InstanceAppProjectionStep;
use App\Models\InstanceAppUpdate;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use App\Models\ProjectUpdate;
use App\Models\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Schedules\FakeScheduleRuntimeAccountResolver;

beforeEach(function (): void {
    // RefreshDatabase's ambient transaction cannot prove durable commit. Use a disposable,
    // separately migrated connection; the observer reads actual committed SQLite state.
    $this->projectionDatabase = tempnam(sys_get_temp_dir(), 'orbit-app-projection-');
    $this->originalConnection = DB::getDefaultConnection();
    $configuration = [...config('database.connections.sqlite'), 'database' => $this->projectionDatabase];
    config(['database.connections.app_projection_test' => $configuration, 'database.connections.app_projection_observer' => $configuration]);
    DB::setDefaultConnection('app_projection_test');
    Artisan::call('migrate', ['--database' => 'app_projection_test', '--force' => true]);
});

afterEach(function (): void {
    DB::setDefaultConnection($this->originalConnection);
    DB::purge('app_projection_test');
    DB::purge('app_projection_observer');
    unlink($this->projectionDatabase);
});

/** @return array{Instance, Process, Schedule} */
function app_projection_fixture(): array
{
    $node = Node::query()->create(['name' => 'projection-dev', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.20', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.3']);
    $node->roles()->create(['role' => 'app-dev', 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Projection', 'slug' => 'projection', 'repository_url' => 'git@example.test:projection.git', 'apps' => [['name' => 'web', 'path' => '.', 'web_root' => 'public', 'type' => 'laravel-app']]]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'environment' => 'development', 'checkout_path' => '/srv/apps/projection', 'branch' => 'main', 'source_layout' => 'checkout', 'provisioning_step' => 'active', 'status' => 'active', 'app_runtime' => ['web' => ['laravel' => false, 'php_version' => '8.5']]]);
    $process = Process::query()->create(['owner_type' => Instance::MorphAlias, 'owner_id' => $instance->id, 'app' => 'web', 'name' => 'worker', 'runtime' => 'systemd', 'working_directory' => '/srv/apps/projection', 'runtime_config' => ['command' => ['true']], 'restart_policy' => 'on-failure', 'desired_state' => 'running', 'status' => 'active']);
    $schedule = Schedule::query()->create(['target_type' => Instance::MorphAlias, 'target_id' => $instance->id, 'host_node_id' => $node->id, 'name' => 'daily', 'calendar' => 'daily', 'command' => 'true', 'timeout_seconds' => 60, 'desired_timer_state' => 'disabled', 'status' => 'active']);

    return [$instance, $process, $schedule];
}

function app_projection_plan(Instance $instance, string $candidate = 'packages/web'): AppProjectionPlan
{
    return new AppProjectionPlan($instance->id, $instance->node_id,
        ['web' => ['path' => '.', 'web_root' => 'public']], ['web' => ['path' => $candidate, 'web_root' => 'public']],
        ['web' => ['php_version' => '8.5']], ['web' => ['php_version' => '8.5']],
        ['checkout_path' => $instance->checkout_path], 'release-a', ['route' => 'retained-route-fingerprint']);
}

function app_projection_project_owner(Instance $instance): ProjectUpdate
{
    return ProjectUpdate::query()->create(['project_id' => $instance->project_id, 'status' => ProjectUpdateStatus::Reserved, 'fingerprint' => 'project-app-request',
        'previous_slug' => $instance->project->slug, 'previous_repository_url' => $instance->project->repository_url,
        'requested_slug' => $instance->project->slug, 'requested_repository_url' => $instance->project->repository_url]);
}

/** @return array{ProjectUpdate|InstanceAppUpdate, InstanceAppProjection} */
function app_projection_reserve(Instance $instance, string $kind): array
{
    $reservation = app(ReserveInstanceAppProjectionsAction::class);
    if ($kind === 'project') {
        $owner = app_projection_project_owner($instance);
        $projection = $reservation->project($owner, [app_projection_plan($instance)], static fn (array $projections): InstanceAppProjection => $projections[0]);

        return [$owner, $projection];
    }

    return $reservation->instance($instance, ['web' => ['path' => 'packages/web', 'web_root' => 'public']], app_projection_plan($instance), static fn (InstanceAppUpdate $owner, InstanceAppProjection $projection): array => [$owner, $projection]);
}

function app_projection_step(string $phase = 'prepare', int $sequence = 1, ?string $restores = null): AppProjectionStepPlan
{
    return new AppProjectionStepPlan("{$phase}-environment", $sequence, $phase, 'web', 'environment-file', 'write',
        $restores === null ? ['path' => '/srv/apps/projection/packages/web/.env'] : ['restores_step_id' => $restores], 'restore-protected-snapshot');
}

final class AppProjectionTestAdapter implements AppProjectionStepAdapter
{
    public int $mutations = 0;

    public int $recoveries = 0;

    public bool $loseResponse = false;

    public bool $fail = false;

    public bool $foreign = false;

    public ?AppProjectionReceipt $receipt = null;

    public function mutate(InstanceAppProjectionStep $step): AppProjectionReceipt
    {
        expect(DB::connection('app_projection_observer')->table('instance_app_projection_steps')->where('id', $step->id)->value('status'))->toBe('intended');
        expect(DB::transactionLevel())->toBe(0);
        $this->mutations++;
        if ($this->fail) {
            throw new ResourceOperationException('env.sync_failed', 'Simulated protected restore failure.', 409);
        }
        $this->receipt = new AppProjectionReceipt($step->id, $step->instance_app_projection_id, $step->receipt_id,
            $step->plan_digest, AppProjectionIdentity::digest($step->intent), 'verified-file-fingerprint', ['before' => 'protected://snapshot/original'], true,
            $step->intent['targets'], ['environment' => ['created' => false, 'protection_fingerprint' => 'mode-0600-owned', 'result_fingerprint' => 'verified-file-fingerprint']]);
        if ($this->loseResponse) {
            throw new RuntimeException('Response lost after remote completion');
        }

        return $this->receipt;
    }

    public function recover(InstanceAppProjectionStep $step): ?AppProjectionReceipt
    {
        expect(DB::connection('app_projection_observer')->table('instance_app_projection_steps')->where('id', $step->id)->exists())->toBeTrue();
        $this->recoveries++;
        if ($this->foreign && $this->receipt !== null) {
            return new AppProjectionReceipt('foreign-step', $this->receipt->projectionId, $this->receipt->receiptId, $this->receipt->planDigest, $this->receipt->intentDigest, $this->receipt->resultFingerprint, $this->receipt->snapshots, true, $this->receipt->targets, $this->receipt->artifacts);
        }

        return $this->receipt;
    }
}

describe('app projection durable owner', function (): void {
    it('refuses a persisted foreign owner at real mutation entrypoints after lock release', function (string $kind, Closure $entrypoint): void {
        [$instance, $process, $schedule] = app_projection_fixture();
        [, $projection] = app_projection_reserve($instance, $kind);
        DB::disconnect('app_projection_test');
        $before = [$instance->fresh()->getAttributes(), $process->fresh()->getAttributes(), $schedule->fresh()->getAttributes()];

        try {
            $result = $entrypoint($instance, $process, $schedule);
            expect($result)->toBeInstanceOf(DeploymentResult::class);
            expect($result->failure?->errorCode)->toBe('instance.lifecycle_busy');
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)->toBe('instance.lifecycle_busy');
            expect($exception->status)->toBe(409);
        }

        expect([$instance->fresh()->getAttributes(), $process->fresh()->getAttributes(), $schedule->fresh()->getAttributes()])->toBe($before);
        expect($projection->fresh()->active_instance_id)->toBe($instance->id);
    })->with(['project', 'instance'])->with([
        'rename' => fn (Instance $i) => app(RenameInstanceAction::class)->execute($i, new RenameInstanceData(branch: 'renamed')),
        'transfer' => fn (Instance $i) => app(TransferInstanceAction::class)->execute($i, new TransferInstanceData($i->node_id, 'other', null)),
        'removal' => fn (Instance $i) => app(RemoveInstanceAction::class)->execute($i, true),
        'development deploy' => fn (Instance $i) => app(DeployInstanceAction::class)->execute($i),
        'rollback' => fn (Instance $i) => app(RollbackInstanceAction::class)->execute($i, 'previous'),
        'environment import' => fn (Instance $i) => app(ImportInstanceEnvironmentAction::class)->execute($i, false),
        'environment import existing' => fn (Instance $i) => app(ImportInstanceEnvironmentAction::class)->importExisting($i),
        'environment update' => fn (Instance $i) => app(UpdateInstanceEnvironmentAction::class)->execute($i, 'APP_ENV', 'testing'),
        'environment sync' => fn (Instance $i) => app(SynchronizeInstanceEnvironmentAction::class)->execute($i),
        'dependency scan' => fn (Instance $i) => app(ScanInstanceDependenciesAction::class)->execute($i),
        'dependency mutation' => fn (Instance $i) => app(UpdateInstanceDependenciesAction::class)->execute($i),
        'Process add' => fn (Instance $i) => app(AddProcessAction::class)->execute(new AddProcessData(ProcessTargetType::Instance, $i->id, 'new-worker', ProcessRuntime::Systemd, ['true'], null, null, [], [], [], 'on-failure', false)),
        'Process start' => fn (Instance $i, Process $p) => app(StartProcessAction::class)->execute($p),
        'Process stop' => fn (Instance $i, Process $p) => app(StopProcessAction::class)->execute($p),
        'Process restart' => fn (Instance $i, Process $p) => app(RestartProcessAction::class)->execute($p),
        'Process remove' => fn (Instance $i, Process $p) => app(RemoveProcessAction::class)->execute($p),
        'Schedule add' => fn (Instance $i) => app(AddScheduleAction::class)->execute(new AddScheduleData(ScheduleTargetType::Instance, $i->id, 'new-daily', 'daily', 'true', 60, false)),
        'Schedule activate' => fn (Instance $i, Process $p, Schedule $s) => app(ActivateScheduleAction::class)->execute($s),
        'Schedule run' => fn (Instance $i, Process $p, Schedule $s) => app(RunScheduleAction::class)->execute($s),
        'Schedule remove' => fn (Instance $i, Process $p, Schedule $s) => app(RemoveScheduleAction::class)->execute($s),
    ]);

    it('refuses persisted owners at production deploy and rollback entrypoints', function (string $kind, string $action): void {
        [$instance] = app_projection_fixture();
        $instance->node->roles()->update(['role' => 'app-prod']);
        $instance->update(['source_layout' => 'production']);
        [, $projection] = app_projection_reserve($instance, $kind);

        $result = $action === 'deploy'
            ? app(DeployInstanceAction::class)->execute($instance)
            : app(RollbackInstanceAction::class)->execute($instance, 'previous');

        expect($result->failure?->errorCode)->toBe('instance.lifecycle_busy');
        expect($projection->fresh()->active_instance_id)->toBe($instance->id);
    })->with(['project', 'instance'])->with(['deploy', 'rollback']);

    it('preserves read-only Schedule logs and inspection under either durable owner', function (string $kind): void {
        [$instance, , $schedule] = app_projection_fixture();
        app_projection_reserve($instance, $kind);
        $targets = new ScheduleTargetResolver(new FakeScheduleRuntimeAccountResolver);
        $keys = Mockery::mock(SshKeyProvider::class);
        $keys->shouldReceive('privateKeyPath')->andReturn('/test/key');
        $hosts = Mockery::mock(KnownHostsStore::class);
        $hosts->shouldReceive('path')->andReturn('/test/known_hosts');
        $certificates = Mockery::mock(LeafCertificateSigner::class);
        $certificates->shouldReceive('rootCertificate')->andReturn('TEST ROOT');
        $renderer = new ScheduleRenderer($certificates, 'https://10.44.0.1');
        $ssh = Mockery::mock(SshExecutor::class);
        $ssh->shouldReceive('execute')->once()->withArgs(fn ($connection, $command): bool => in_array('journalctl', $command->arguments, true))
            ->andReturn(new CommandResult(0, "worker output\n", '', 0, false));
        $ssh->shouldReceive('execute')->once()->withArgs(fn ($connection, $command): bool => ! in_array('journalctl', $command->arguments, true))
            ->andReturn(new CommandResult(0, "1|1|1|1|1|1|1\n", '', 0, false));
        $runtime = new RemoteScheduleRuntimeManager($targets, $renderer, $ssh, $keys, $hosts);
        $inspector = new NativeScheduleStateInspector($targets, $renderer, $ssh, $keys, $hosts);

        $logs = new ShowScheduleLogsAction($runtime)->execute($schedule);
        expect($logs->output)->toBe("worker output\n");
        expect($targets->forInspection($schedule)->instance?->id)->toBe($instance->id);
        expect($inspector->inspect($schedule))->toBeInstanceOf(ScheduleInspectionData::class);
        expect(fn () => $targets->forSchedule($schedule))->toThrow(fn (ResourceOperationException $e): bool => $e->errorCode === 'instance.lifecycle_busy');
        expect(fn () => $targets->forRemoval($schedule))->toThrow(ResourceOperationException::class);
        $schedule->host_node_id = $instance->node_id + 100;
        expect(fn () => new ShowScheduleLogsAction($runtime)->execute($schedule))->toThrow(ResourceOperationException::class);
        $instance->update(['provisioning_step' => 'preparing']);
        expect(fn () => $targets->forSchedule($schedule, mutation: false))->toThrow(ResourceOperationException::class);
    })->with(['project', 'instance']);

    it('keeps unrelated environment lock errors and makes updates work after owner completion', function (): void {
        [$instance] = app_projection_fixture();
        [$owner] = app_projection_reserve($instance, 'instance');
        $owner->update(['published_at' => now(), 'phase' => 'published']);
        app(RunInstanceAppProjectionAction::class)->complete($owner, ['published' => true]);
        $result = app(UpdateInstanceEnvironmentAction::class)->execute($instance, 'APP_ENV', 'testing');
        expect($result->changed)->toBeTrue();
        expect($instance->environmentValues()->where('env_key', 'APP_ENV')->sole()->env_value)->toBe('testing');
        $busy = new class implements InstanceEnvironmentOperationLock
        {
            public function run(array $instanceIds, Closure $operation): mixed
            {
                throw new ResourceOperationException('env.operation_busy', 'Existing lock error.', 409);
            }
        };
        $admission = new AdmitInstanceAppMutationAction($busy);
        expect(fn () => $admission->execute([$instance->id], fn () => null))->toThrow(fn (ResourceOperationException $e): bool => $e->errorCode === 'env.operation_busy');
        expect(fn () => $admission->execute([$instance->id], fn () => null, 'process.operation_busy'))->toThrow(fn (ResourceOperationException $e): bool => $e->errorCode === 'process.operation_busy');
    });

    it('reserves the entire Project Instance set atomically or none', function (): void {
        [$instance] = app_projection_fixture();
        $second = $instance->replicate();
        $second->name = 'second';
        $second->checkout_path = '/srv/apps/projection-second';
        $second->save();
        [, $foreign] = app_projection_reserve($second, 'instance');
        $owner = app_projection_project_owner($instance);

        expect(fn () => app(ReserveInstanceAppProjectionsAction::class)->project($owner, [app_projection_plan($instance), app_projection_plan($second)], fn () => throw new RuntimeException('Must not preflight')))
            ->toThrow(fn (ResourceOperationException $e): bool => $e->errorCode === 'instance.lifecycle_busy');

        expect(InstanceAppProjection::query()->where('project_update_id', $owner->id)->count())->toBe(0);
        expect($foreign->fresh()->active_instance_id)->toBe($second->id);
    });

    it('commits the complete Project set before callbacks and keeps it fixed on retry', function (): void {
        [$instance] = app_projection_fixture();
        $second = $instance->replicate();
        $second->name = 'second';
        $second->checkout_path = '/srv/apps/projection-second';
        $second->save();
        $owner = app_projection_project_owner($instance);
        $plans = [app_projection_plan($instance), app_projection_plan($second)];
        $callback = function (array $children) use ($instance, $second): array {
            expect(DB::connection('app_projection_observer')->table('instance_app_projections')->whereNotNull('active_instance_id')->count())->toBe(2);
            // The same existing operation locks allow subset reentrancy, not another hierarchy.
            app(InstanceEnvironmentOperationLock::class)->run([$instance->id, $second->id], fn () => null);

            return array_map(fn (InstanceAppProjection $child): string => $child->id, $children);
        };
        $reservation = app(ReserveInstanceAppProjectionsAction::class);

        $first = $reservation->project($owner, $plans, $callback);
        $retry = $reservation->project($owner->fresh(), $plans, $callback);

        expect($retry)->toBe($first);
        expect(fn () => $reservation->project($owner, [$plans[0]], fn () => null))->toThrow(ResourceOperationException::class);
        expect(fn () => $owner->update(['fingerprint' => 'another-request']))->toThrow(LogicException::class);
        expect(AppProjectionIdentity::digest(['apps' => [['name' => 'web', 'path' => '.'], ['name' => 'api', 'path' => 'api']]]))
            ->toBe(AppProjectionIdentity::digest(['apps' => [['path' => 'api', 'name' => 'api'], ['path' => '.', 'name' => 'web']]]));
    });

    it('rejects a different request but resumes equivalent maps without changing the immutable plan', function (): void {
        [$instance] = app_projection_fixture();
        [$owner, $projection] = app_projection_reserve($instance, 'instance');
        $action = app(ReserveInstanceAppProjectionsAction::class);

        $resumed = $action->instance($instance, ['web' => ['web_root' => 'public', 'path' => 'packages/web']], app_projection_plan($instance, 'must-not-recapture'), fn ($parent, $child) => $child);
        expect($resumed->id)->toBe($projection->id);
        expect($resumed->plan['candidate_apps']['web']['path'])->toBe('packages/web');
        expect(fn () => $action->instance($instance, ['web' => ['path' => 'different']], app_projection_plan($instance), fn () => null))
            ->toThrow(fn (ResourceOperationException $e): bool => $e->errorCode === 'instance.app_update_in_progress');
        expect(fn () => $projection->update(['instance_app_update_id' => 'foreign']))->toThrow(LogicException::class);
        expect(fn () => $owner->update(['fingerprint' => 'different']))->toThrow(LogicException::class);
    });
});

describe('app projection step recovery', function (): void {
    it('commits intent before mutation and recovers a lost response without recapturing snapshots', function (string $kind): void {
        [$instance] = app_projection_fixture();
        [, $projection] = app_projection_reserve($instance, $kind);
        $adapter = new AppProjectionTestAdapter;
        $adapter->loseResponse = true;
        $runner = app(RunInstanceAppProjectionAction::class);

        expect(fn () => $runner->step($projection, app_projection_step(), $adapter))->toThrow(RuntimeException::class);
        $intent = InstanceAppProjectionStep::query()->sole();
        expect($intent->status)->toBe('intended');
        DB::disconnect('app_projection_test');
        $runner = app(RunInstanceAppProjectionAction::class);
        $receipt = $runner->step($projection->fresh(), app_projection_step(), $adapter);
        $runner->step($projection, app_projection_step(), $adapter);

        expect($adapter->mutations)->toBe(1);
        expect($adapter->recoveries)->toBe(2);
        expect($receipt->snapshots)->toBe(['before' => 'protected://snapshot/original']);
        expect($intent->fresh()->status)->toBe('complete');
        expect(InstanceAppProjectionStep::query()->count())->toBe(1);
    })->with(['project', 'instance']);

    it('blocks fresh prepare behind hard-crash intent until durable evidence is recovered by a fresh adapter', function (string $kind): void {
        [$instance] = app_projection_fixture();
        [, $projection] = app_projection_reserve($instance, $kind);
        $plan = app_projection_step();
        // Exact state after hard exit: committed intent, partial remote work, no catch or acknowledgment.
        $step = InstanceAppProjectionStep::query()->create([
            'id' => (string) Str::uuid(), 'instance_app_projection_id' => $projection->id,
            'step_key' => $plan->key, 'sequence' => 1, 'plan_digest' => $projection->plan_digest,
            'intent' => [...$plan->evidence(), 'instance_id' => $instance->id, 'node_id' => $instance->node_id,
                'project_update_id' => $projection->project_update_id, 'instance_app_update_id' => $projection->instance_app_update_id],
            'receipt_id' => (string) Str::uuid(), 'status' => 'intended',
        ]);
        $directory = sys_get_temp_dir().'/orbit-hard-crash-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        file_put_contents($directory.'/snapshot', 'original');
        file_put_contents($directory.'/target', 'partially-written-candidate');
        $receipt = new AppProjectionReceipt($step->id, $projection->id, $step->receipt_id, $step->plan_digest,
            AppProjectionIdentity::digest($step->intent), '', ['before' => $directory.'/snapshot'], false,
            $step->intent['targets'], ['environment' => ['created' => false, 'protection_fingerprint' => 'owned-0600', 'result_fingerprint' => '']]);
        file_put_contents($directory.'/receipt', json_encode($receipt->evidence(), JSON_THROW_ON_ERROR));
        unset($receipt, $projection);
        DB::disconnect('app_projection_test');
        try {
            $projection = InstanceAppProjection::query()->sole();
            $runner = app(RunInstanceAppProjectionAction::class);
            $adapter = new class($directory) implements AppProjectionStepAdapter
            {
                public int $mutations = 0;

                public int $recoveries = 0;

                public function __construct(private string $directory) {}

                public function mutate(InstanceAppProjectionStep $step): AppProjectionReceipt
                {
                    $this->mutations++;
                    throw new LogicException('Fresh preparation must not run behind uncertain evidence.');
                }

                public function recover(InstanceAppProjectionStep $step): ?AppProjectionReceipt
                {
                    $this->recoveries++;
                    expect(file_get_contents($this->directory.'/snapshot'))->toBe('original');
                    expect(file_get_contents($this->directory.'/target'))->toBe('partially-written-candidate');
                    $e = json_decode(file_get_contents($this->directory.'/receipt'), true, flags: JSON_THROW_ON_ERROR);
                    expect($e['complete'])->toBeFalse();
                    file_put_contents($this->directory.'/target', 'verified-restored-candidate');
                    $e['complete'] = true;
                    $e['result_fingerprint'] = 'verified-restored-candidate';
                    $e['artifacts']['environment']['result_fingerprint'] = 'verified-restored-candidate';
                    file_put_contents($this->directory.'/receipt', json_encode($e, JSON_THROW_ON_ERROR));

                    return new AppProjectionReceipt($e['step_id'], $e['projection_id'], $e['receipt_id'], $e['plan_digest'], $e['intent_digest'], $e['result_fingerprint'], $e['snapshots'], $e['complete'], $e['targets'], $e['artifacts']);
                }
            };
            $next = new AppProjectionStepPlan('next-prepare', 2, 'prepare', 'web', 'other-file', 'write', ['path' => '/next'], 'restore');
            expect($step->fresh()->error_code)->toBeNull();
            expect(fn () => $runner->step($projection, $next, $adapter))
                ->toThrow(fn (ResourceOperationException $e): bool => $e->errorCode === 'app.projection_recovery_required');
            expect($adapter->mutations)->toBe(0);
            $runner->step($projection, $plan, $adapter);
            expect($step->fresh()->status)->toBe('complete');
            expect($adapter->recoveries)->toBe(1);
            expect(file_get_contents($directory.'/snapshot'))->toBe('original');
            $runner->step($projection, $next, new AppProjectionTestAdapter);
            expect(InstanceAppProjectionStep::query()->count())->toBe(2);
        } finally {
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    })->with(['project', 'instance']);

    it('fails closed on absent or foreign receipts and retains durable ownership', function (bool $foreign): void {
        [$instance] = app_projection_fixture();
        [, $projection] = app_projection_reserve($instance, 'instance');
        $adapter = new AppProjectionTestAdapter;
        $adapter->loseResponse = true;
        $runner = app(RunInstanceAppProjectionAction::class);
        expect(fn () => $runner->step($projection, app_projection_step(), $adapter))->toThrow(RuntimeException::class);
        if ($foreign) {
            $adapter->foreign = true;
        } else {
            $adapter->receipt = null;
        }

        expect(fn () => $runner->step($projection, app_projection_step(), $adapter))
            ->toThrow(fn (ResourceOperationException $e): bool => $e->errorCode === 'app.projection_receipt_conflict');

        expect($adapter->mutations)->toBe(1);
        expect($projection->fresh()->active_instance_id)->toBe($instance->id);
    })->with([false, true]);

    it('keeps a failed restore owned and preserves the specific infrastructure error', function (): void {
        [$instance] = app_projection_fixture();
        [$owner, $projection] = app_projection_reserve($instance, 'instance');
        $runner = app(RunInstanceAppProjectionAction::class);
        $prepare = new AppProjectionTestAdapter;
        $runner->step($projection, app_projection_step(), $prepare);
        $prepared = InstanceAppProjectionStep::query()->sole();
        $restore = new AppProjectionTestAdapter;
        $restore->fail = true;

        expect(fn () => $runner->step($projection, app_projection_step('restore', 2, $prepared->id), $restore))
            ->toThrow(fn (ResourceOperationException $e): bool => $e->errorCode === 'env.sync_failed');
        expect(fn () => $runner->complete($owner, ['restored' => true]))
            ->toThrow(fn (ResourceOperationException $e): bool => $e->errorCode === 'app.projection_incomplete');

        $newPrepare = new AppProjectionStepPlan('new-prepare', 3, 'prepare', 'web', 'another-file', 'write', ['path' => '/new'], 'restore');
        expect(fn () => $runner->step($projection, $newPrepare, new AppProjectionTestAdapter))
            ->toThrow(fn (ResourceOperationException $e): bool => $e->errorCode === 'app.projection_recovery_required');
        expect($projection->fresh()->active_instance_id)->toBe($instance->id);
        expect($owner->fresh()->completion)->toBeNull();
        expect(InstanceAppProjectionStep::query()->where('step_key', 'restore-environment')->sole()->error_code)->toBe('env.sync_failed');
    });

    it('selects recovery from the parent publication boundary and completes idempotently', function (string $kind): void {
        [$instance] = app_projection_fixture();
        [$owner, $projection] = app_projection_reserve($instance, $kind);
        $runner = app(RunInstanceAppProjectionAction::class);
        expect($runner->recoveryDirection($projection))->toBe('restore');
        $prepare = new AppProjectionTestAdapter;
        $runner->step($projection, app_projection_step(), $prepare);
        if ($owner instanceof ProjectUpdate) {
            $owner->update(['status' => ProjectUpdateStatus::Publishing]);
        } else {
            $owner->update(['published_at' => now(), 'phase' => 'published']);
        }
        DB::disconnect('app_projection_test');

        expect($runner->recoveryDirection($projection))->toBe('forward');
        expect(fn () => $runner->step($projection, app_projection_step('restore', 2), new AppProjectionTestAdapter))->toThrow(LogicException::class);
        $cleanup = new AppProjectionTestAdapter;
        $cleanup->loseResponse = true;
        expect(fn () => $runner->step($projection, app_projection_step('cleanup', 2), $cleanup))->toThrow(RuntimeException::class);
        $runner->step($projection, app_projection_step('cleanup', 2), $cleanup);
        $runner->complete($owner, ['published' => true]);
        $runner->complete($owner->fresh(), ['published' => true]);

        expect($projection->fresh()->active_instance_id)->toBeNull();
        expect($projection->fresh()->completion)->toBe(['published' => true]);
        expect($prepare->mutations)->toBe(1);
        expect($cleanup->mutations)->toBe(1);
        expect($cleanup->recoveries)->toBe(1);
        app(InstanceEnvironmentOperationLock::class)->run([$instance->id], fn () => InstanceAppProjection::assertAvailable([$instance->id]));
    })->with(['project', 'instance']);

    it('resumes a lost restore response and releases only after its receipt is verified', function (): void {
        [$instance] = app_projection_fixture();
        [$owner, $projection] = app_projection_reserve($instance, 'instance');
        $runner = app(RunInstanceAppProjectionAction::class);
        $runner->step($projection, app_projection_step(), new AppProjectionTestAdapter);
        $prepare = InstanceAppProjectionStep::query()->sole();
        $restore = new AppProjectionTestAdapter;
        $restore->loseResponse = true;
        $plan = app_projection_step('restore', 2, $prepare->id);
        expect(fn () => $runner->step($projection, $plan, $restore))->toThrow(RuntimeException::class);
        expect($projection->fresh()->active_instance_id)->toBe($instance->id);
        DB::disconnect('app_projection_test');

        $runner->step($projection, $plan, $restore);
        $runner->complete($owner, ['restored' => true]);
        $runner->complete($owner->fresh(), ['restored' => true]);

        expect($restore->mutations)->toBe(1);
        expect($restore->recoveries)->toBe(1);
        expect($owner->fresh()->phase)->toBe('rolled_back');
        expect($projection->fresh()->active_instance_id)->toBeNull();
    });

    it('retains ownership when a previously acknowledged receipt becomes foreign', function (): void {
        [$instance] = app_projection_fixture();
        [$owner, $projection] = app_projection_reserve($instance, 'instance');
        $runner = app(RunInstanceAppProjectionAction::class);
        $adapter = new AppProjectionTestAdapter;
        $runner->step($projection, app_projection_step(), $adapter);
        $adapter->foreign = true;
        expect(fn () => $runner->step($projection, app_projection_step(), $adapter))->toThrow(ResourceOperationException::class);
        $owner->update(['published_at' => now(), 'phase' => 'published']);

        expect(fn () => $runner->complete($owner, ['published' => true]))->toThrow(ResourceOperationException::class);

        expect($projection->fresh()->active_instance_id)->toBe($instance->id);
        expect(InstanceAppProjectionStep::query()->sole()->error_code)->toBe('app.projection_receipt_conflict');
    });

    it('rejects lifecycle callbacks inside an uncommitted transaction', function (): void {
        [$instance] = app_projection_fixture();
        $called = false;
        DB::beginTransaction();
        try {
            expect(fn () => app(ReserveInstanceAppProjectionsAction::class)->instance($instance, [], app_projection_plan($instance), function () use (&$called): void {
                $called = true;
            }))->toThrow(LogicException::class);
        } finally {
            DB::rollBack();
        }

        expect($called)->toBeFalse();
        expect(InstanceAppProjection::query()->count())->toBe(0);
    });

    it('replays a completed receipt without steps and permits later Instance deletion', function (): void {
        [$instance, $process, $schedule] = app_projection_fixture();
        [$owner, $projection] = app_projection_reserve($instance, 'instance');
        $runner = app(RunInstanceAppProjectionAction::class);
        $owner->update(['published_at' => now(), 'phase' => 'published']);
        $runner->complete($owner, ['published' => true]);
        $retry = app_projection_plan($instance, 'must-not-recapture');

        $resumed = app(ReserveInstanceAppProjectionsAction::class)->instance($instance, ['web' => ['path' => 'packages/web', 'web_root' => 'public']], $retry,
            fn (InstanceAppUpdate $parent, InstanceAppProjection $child): array => [$parent->id, $child->id, $child->completion]);
        expect($resumed)->toBe([$owner->id, $projection->id, ['published' => true]]);
        expect(fn () => $runner->complete($owner, ['different' => true]))->toThrow(LogicException::class);
        $process->delete();
        $schedule->delete();
        $instance->delete();

        expect($projection->fresh()->instance_id)->toBe($instance->id);
        expect($projection->fresh()->completion)->toBe(['published' => true]);
    });

    it('requires acknowledged restore evidence before prepublication completion', function (): void {
        [$instance] = app_projection_fixture();
        [$owner, $projection] = app_projection_reserve($instance, 'instance');
        $runner = app(RunInstanceAppProjectionAction::class);
        $runner->step($projection, app_projection_step(), new AppProjectionTestAdapter);
        $step = InstanceAppProjectionStep::query()->sole();

        expect(fn () => $runner->complete($owner, ['restored' => true]))->toThrow(ResourceOperationException::class);
        $runner->step($projection, app_projection_step('restore', 2, $step->id), new AppProjectionTestAdapter);
        $runner->complete($owner, ['restored' => true]);

        expect($owner->fresh()->phase)->toBe('rolled_back');
        expect($projection->fresh()->active_instance_id)->toBeNull();
    });
});
