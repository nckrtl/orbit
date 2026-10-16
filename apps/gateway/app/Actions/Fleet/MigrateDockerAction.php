<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerScopeLock;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\Nodes\NodeUpdateLock;
use App\Infrastructure\Nodes\Roles\NodeRoleConvergeLock;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Infrastructure\RemotePrograms\RemoteProgramCatalog;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

/** Explicit operator operation, never called by convergence or nightly maintenance. */
final readonly class MigrateDockerAction
{
    public function __construct(
        private ManagedNodeEligibility $eligibility,
        private NodeRoleConvergeLock $roles,
        private NodeUpdateLock $updates,
        private ToolManagerScopeLock $tools,
        private RemoteProgramRunner $programs,
        private RemoteProgramCatalog $catalog,
        private ProcessTargetResolver $targets,
        private ProcessRuntimeManager $runtime,
    ) {}

    /** @return array<string, mixed> */
    public function execute(Node $node, MachineRecipe $recipe, bool $dryRun, bool $fallback = false): array
    {
        if (! $this->eligibility->allows($node)) {
            throw new ResourceOperationException('node.docker_migration_unsupported', 'Docker migration requires an active managed Linux Node.', 422);
        }
        // Resolve stored specs before any stop. A claimed Orbit container without a matching spec refuses remotely.
        $processes = Process::query()->with('owner')->where('runtime', ProcessRuntime::Docker->value)->get()
            ->filter(fn (Process $process): bool => ($process->owner instanceof Node ? $process->owner->id : $process->owner->node_id) === $node->id);
        $ids = array_values($processes->map(fn (Process $process): string => (string) $process->id)->all());
        $states = $processes->mapWithKeys(fn (Process $process): array => [(string) $process->id => $process->desired_state->value])->all();
        if ($dryRun) {
            return $this->remote($node, $recipe, $ids, $states, $processes, 'plan', $fallback);
        }

        return $this->roles->run($node, fn (): array => $this->tools->run($node->id, ToolManagerName::Apt, fn (): array => $this->updates->run($node, function () use ($node, $recipe, $processes, $ids, $states, $fallback): array {
            foreach ($processes as $process) {
                if ($this->targets->forInstallation($process)->node->id !== $node->id) {
                    throw new RuntimeException('The Docker Process target changed. Nothing was migrated.');
                }
            }
            $prepared = $this->remote($node, $recipe, $ids, $states, $processes, 'prepare', $fallback);
            if (isset($prepared['failure'])) {
                return $prepared;
            }
            $recorded = $prepared['process_ids'] ?? [];
            if (! is_array($recorded) || ! array_is_list($recorded) || array_any($recorded, fn (mixed $id): bool => ! is_string($id) || ! in_array($id, $ids, true))) {
                throw new RuntimeException('Migration returned unknown Process IDs. Resume after checking stored specs.');
            }
            $failed = [];
            foreach ($processes as $process) {
                if (in_array((string) $process->id, $recorded, true)) {
                    try {
                        // converge recreates missing containers from their current stored specs and honors desired_state.
                        $this->runtime->converge($process);
                    } catch (Throwable) {
                        // Still attempt the remaining services and report all final states. Never expose runtime output.
                        $failed[] = $process->id;
                    }
                }
            }
            $report = $this->remote($node, $recipe, $ids, $states, $processes, 'report');
            if ($failed !== []) {
                $report['healthy'] = false;
                $report['failed_process_ids'] = $failed;
            }

            return $report;
        })), errorCode: 'node.docker_migration_failed');
    }

    /**
     * @param  list<string>  $ids
     * @param  array<int|string, string>  $states
     * @param  Collection<int, Process>  $processes
     * @return array<string, mixed>
     */
    private function remote(Node $node, MachineRecipe $recipe, array $ids, array $states, Collection $processes, string $mode, bool $fallback = false): array
    {
        $specs = [];
        foreach ($processes as $process) {
            $target = $this->targets->forInstallation($process);
            if ($target->node->id !== $node->id) {
                throw new RuntimeException('A Docker Process target changed. Resume after checking stored specs.');
            }
            $renderer = new DockerProcessRenderer;
            $specs[(string) $process->id] = ['name' => $renderer->containerName($process), 'hash' => $renderer->specHash($process, $target),
                'volumes' => $process->runtime_config['volumes'] ?? []];
        }
        $payload = ['mode' => $mode, 'fallback' => $fallback, 'recipe' => $recipe->identity, 'snapshot' => $recipe->snapshot,
            'docker_version' => $recipe->tools['docker.io']->version, 'containerd_version' => $recipe->tools['containerd']->version,
            'process_ids' => $ids, 'process_states' => (object) $states, 'process_specs' => (object) $specs,
            'architecture' => in_array($node->architecture, ['x86_64', 'amd64'], true) ? 'amd64' : 'arm64'];
        $result = $this->programs->run($node, $this->catalog->program('docker.migrate', 'root'), $payload);
        $report = $result->data;
        if ($result->outcome === 'failed') {
            $report['healthy'] = false;
            $report['message'] = 'Resume the same command with the same recipe after resolving the failure. Use --fallback only after reviewing a fallback-required refusal. No volume data was deleted.';
        }

        $fields = [];
        foreach ($report as $key => $value) {
            if (! is_string($key)) {
                throw new RuntimeException('Docker migration returned an invalid report key.');
            }
            $fields[$key] = $value;
        }

        return $fields;
    }
}
