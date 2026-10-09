<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Compute\SandboxState;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskTopology;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Compute\SandboxFleetIdentity;
use App\Infrastructure\Compute\TaskSandboxDrivers;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskSandbox;
use Illuminate\Support\Str;
use Throwable;

/** Routes task operations by persisted workspace ownership; never by available capacity. */
final readonly class TaskWorkspaceExecutor
{
    public function __construct(private DevelopmentSshExecutor $shared, private IncusSandboxHost $host, private TaskSandboxDrivers $drivers, private SandboxFleetIdentity $identity) {}

    public function execute(Instance $workspace, RemoteCommand $command, string $step, string $errorCode, ?float $commandTimeout = null, string $failureLabel = 'Task workspace', string $role = 'operator'): CommandResult
    {
        if (! in_array($role, ['operator', 'gateway', ...TaskTopology::Roles], true) || ($role !== 'operator' && $workspace->task_sandbox_id === null)) {
            throw new RuntimeConvergenceException($step, $errorCode, 'The requested sandbox role is unavailable.');
        }
        if ($workspace->task_sandbox_id === null) {
            if (Task::topLevel()->where('taskable_type', $workspace->getMorphClass())->where('taskable_id', $workspace->id)->where('task_compute', TaskCompute::Vm->value)->exists()) {
                throw new RuntimeConvergenceException($step, $errorCode, 'The VM task workspace has no sandbox reservation.');
            }

            return $this->shared->execute($workspace->node, $command, $step, $errorCode, $commandTimeout, $failureLabel);
        }
        try {
            $sandbox = $workspace->taskSandbox?->fresh();
            $group = $sandbox?->group;
            if (! $sandbox instanceof TaskSandbox || $group === null || $group->task_compute !== TaskCompute::Vm
                || $group->project_id !== $workspace->project_id || $group->taskable_id !== $workspace->id
                || $group->taskable_type !== (new Instance)->getMorphClass()
                || $sandbox->state !== SandboxState::Running || $sandbox->desired_power !== 'running') {
                throw new RuntimeConvergenceException($step, $errorCode, 'The task sandbox ownership or running state is unavailable.');
            }
            if ($role !== 'operator' && ($sandbox->provider !== 'incus' || $group->project->slug !== 'orbit'
                || ! is_array($sandbox->spec['images'] ?? null) || ! is_string($sandbox->spec['images'][$role] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/D', $sandbox->spec['images'][$role]) !== 1
                || (in_array($role, TaskTopology::Roles, true) && ! $this->workloadSourceMatches($workspace, $sandbox)))) {
                throw new RuntimeConvergenceException($step, $errorCode, 'The requested sandbox role is unavailable.');
            }
            if ($sandbox->provider === 'upcloud' || ($sandbox->provider === 'incus' && $group->project->slug !== 'orbit')) {
                if ($sandbox->node_id !== $workspace->node_id || $group->project->slug === 'orbit') {
                    throw new RuntimeConvergenceException($step, $errorCode, 'The project sandbox has no matching enrolled Node.');
                }

                $this->identity->assertReady($sandbox, $workspace->node);

                return $this->shared->execute($workspace->node, $command, $step, $errorCode, $commandTimeout, $failureLabel);
            }
            if ($sandbox->provider !== 'incus') {
                throw new RuntimeConvergenceException($step, $errorCode, 'The task sandbox provider is unavailable.');
            }
            $hostId = $sandbox->spec['host_id'] ?? null;
            $settings = array_find($this->drivers->localHosts(), fn (array $candidate): bool => $candidate['node_id'] === $hostId);
            $node = is_int($hostId) ? Node::query()->find($hostId) : null;
            $expectedNodeId = $hostId;
            if ($settings === null || ! $node instanceof Node || $workspace->node_id !== $expectedNodeId
                || ($sandbox->spec['project'] ?? null) !== $settings['project']) {
                throw new RuntimeConvergenceException($step, $errorCode, 'The recorded sandbox host is unavailable.');
            }
            if ($commandTimeout !== null && $command->timeout === null) {
                $command = new RemoteCommand($command->arguments, input: $command->input, protectedInput: $command->protectedInput, maxOutputBytes: $command->maxOutputBytes, output: $command->output, cancelled: $command->cancelled, timeout: $commandTimeout, terminateGraceSeconds: $command->terminateGraceSeconds);
            }
            $result = $this->host->executeGuest($node, $settings['project'], $sandbox->id, $settings['max_vms'], $command, $role);
            if (! $result->succeeded()) {
                throw new RuntimeConvergenceException($step, $errorCode, $failureLabel.' step ['.$step.'] failed in the sandbox.', result: $result);
            }

            return $result;
        } catch (RuntimeConvergenceException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new RuntimeConvergenceException($step, $errorCode, 'The task sandbox could not be reached.', previous: $exception);
        }
    }

    private function workloadSourceMatches(Instance $workspace, TaskSandbox $sandbox): bool
    {
        $template = $sandbox->spec['source_template'] ?? null;
        $repository = GitHubRepository::fromOrigin($workspace->project->repository_url);

        return is_array($template) && count($template) === 4
            && is_string($template['id'] ?? null) && Str::isUuid($template['id']) && strtolower($template['id']) === $template['id']
            && is_string($template['commit'] ?? null) && preg_match('/\A[a-f0-9]{40}(?:[a-f0-9]{24})?\z/D', $template['commit']) === 1
            && is_string($template['base'] ?? null) && GitBranchName::isValid($template['base'])
            && $template['base'] === $workspace->project->default_branch
            && $repository instanceof GitHubRepository
            && ($template['repository'] ?? null) === 'https://github.com/'.$repository->owner.'/'.$repository->name.'.git';
    }
}
