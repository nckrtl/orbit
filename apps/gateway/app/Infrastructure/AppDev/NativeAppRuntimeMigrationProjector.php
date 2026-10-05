<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Actions\Instances\MigrateAppRuntimeAction;
use App\Domain\AppDev\AppRuntimeMigrationProjector;
use App\Domain\AppDev\VitePortRuntime;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Processes\ProcessTarget;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppRuntimeMigration;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;

final readonly class NativeAppRuntimeMigrationProjector implements AppRuntimeMigrationProjector
{
    public function __construct(private DevelopmentSshExecutor $ssh, private SystemdProcessRenderer $systemd, private ProcessTargetResolver $targets, private ManagedUserAccountResolver $accounts, private RemoteAppDevCaddyManager $caddy, private VitePortRuntime $ports) {}

    public function prepare(Node $node, AppRuntimeMigration $migration): array
    {
        return $this->command($node, $migration, 'prepare');
    }

    public function activate(Node $node, AppRuntimeMigration $migration): void
    {
        $this->command($node, $migration, 'activate');
        $running = $migration->observations['running'] ?? [];
        if (! is_array($running)) {
            throw new ResourceOperationException('instance.lifecycle_conflict', 'Invalid migration running-state observations.', 409);
        }
        foreach ($migration->plan['processes'] as $record) {
            $process = Process::query()->findOrFail($record['id']);
            $unit = $this->systemd->unitName($process);
            if (($running[$unit] ?? false) !== true) {
                continue;
            }
            $target = $this->targets->forInspection($process);
            if (! $target->instance instanceof Instance || $target->node->id !== $node->id) {
                throw new ResourceOperationException('instance.lifecycle_conflict', 'A migration placement changed.', 409);
            }
            $instance = MigrateAppRuntimeAction::candidate($target->instance, $migration);
            $kind = match (true) {
                $process->isVpDev() => 'vite_port', $process->isAgentationMcp() => 'agentation_port', $process->isAnnotator() => 'annotator_port', default => null
            };
            if ($kind !== null) {
                $port = $instance->runtimeForApp($process->app)[$kind];
                if (! is_int($port)) {
                    throw new ResourceOperationException('instance.lifecycle_conflict', 'The migration endpoint has no port.', 409);
                }
                $ready = false;
                for ($attempt = 0; $attempt < 30; $attempt++) {
                    $ready = $process->isVpDev() ? $this->ports->ready($process, $instance, $port) : $this->ports->ownsListener($process, $instance, $port);
                    if ($ready) {
                        break;
                    }
                    usleep(250_000);
                }
                if (! $ready) {
                    throw new ResourceOperationException('instance.lifecycle_conflict', 'The migrated endpoint did not become ready.', 409);
                }
            }
        }
        $this->caddy->build($node);
    }

    public function rollback(Node $node, AppRuntimeMigration $migration): void
    {
        $this->command($node, $migration, 'rollback');
    }

    public function cleanup(Node $node, AppRuntimeMigration $migration): void
    {
        $this->command($node, $migration, 'cleanup');
    }

    /** @return array<string,mixed> */
    private function command(Node $node, AppRuntimeMigration $migration, string $action): array
    {
        $files = $units = [];
        if (in_array($action, ['prepare', 'activate'], true)) {
            foreach ($migration->plan['processes'] as $record) {
                $process = Process::query()->findOrFail($record['id']);
                if ($process->app !== $record['app'] || MigrateAppRuntimeAction::fingerprint($process) !== $record['fingerprint']) {
                    throw new ResourceOperationException('instance.lifecycle_conflict', 'A frozen migration Process changed.', 409);
                }
                $target = $this->targets->forInspection($process);
                if (! $target->instance instanceof Instance || $target->node->id !== $node->id) {
                    throw new ResourceOperationException('instance.lifecycle_conflict', 'A migration placement changed.', 409);
                }
                $instance = MigrateAppRuntimeAction::candidate($target->instance, $migration);
                $candidate = new ProcessTarget($target->node, $target->user, $target->checkoutPath, $target->certificateScope, $instance, $target->environmentFile, $target->productionReleaseLayout, $target->routeDomain, $target->onDemandHostStart, $target->app);
                $unit = $this->systemd->unitName($process);
                $units[] = $unit;
                $files[] = ['path' => $this->systemd->unitPath($process), 'tag' => "X-Orbit-Process-ID={$process->id}", 'contents' => base64_encode($this->systemd->render($process, $candidate, $this->accounts->resolve($node)))];
                if ($process->isVpDev()) {
                    $app = $instance->appConfiguration($process->app)['name'];
                    $port = $instance->runtimeForApp($app)['vite_port'];
                    $qualifiedApp = $instance->usesAppViteIdentity($app) ? $app : null;
                    $tag = SystemdProcessRenderer::viteEnvironmentMarker($instance->id, $qualifiedApp);
                    $files[] = ['path' => SystemdProcessRenderer::viteEnvironmentPath($instance->id, $qualifiedApp), 'tag' => $tag, 'contents' => base64_encode("{$tag}\nORBIT_DEV_SERVER_PORT={$port}\n")];
                }
            }
        }
        $payload = ['id' => $migration->id, 'plan_digest' => hash('sha256', json_encode($migration->plan, JSON_THROW_ON_ERROR)), 'action' => $action, 'files' => $files, 'units' => $units, 'stores' => $migration->plan['stores'], 'retired_files' => array_map(static fn (array $file): array => ['path' => $file['old'], 'tag' => "# Orbit Instance {$file['instance_id']}"], $migration->plan['vite_files'] ?? []), 'user' => $this->accounts->resolve($node)->user];
        $result = $this->ssh->execute($node, new RemoteCommand(arguments: ['sudo', 'python3', '-c', AppRuntimeMigrationProgram::script()], protectedInput: ProtectedInput::fromString(json_encode($payload, JSON_THROW_ON_ERROR)), maxOutputBytes: 8192), 'app-runtime-migration', 'process.environment_projection_failed');
        $observations = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($observations) || array_any(array_keys($observations), static fn ($key): bool => ! is_string($key))) {
            throw new ResourceOperationException('instance.lifecycle_conflict', 'Invalid runtime migration observation.', 409);
        }

        $bounded = [];
        foreach ($observations as $key => $value) {
            if (! is_string($key)) {
                throw new ResourceOperationException('instance.lifecycle_conflict', 'Invalid runtime migration observation key.', 409);
            }
            $bounded[$key] = $value;
        }

        return $bounded;
    }
}
