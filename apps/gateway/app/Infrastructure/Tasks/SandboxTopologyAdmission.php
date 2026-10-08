<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Actions\Compute\ExpandTaskSandboxAction;
use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxState;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskTopology;
use App\Domain\Tasks\TaskTopologyAdmission;
use App\Infrastructure\Compute\TaskSandboxDrivers;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;
use App\Models\Task;
use App\Models\TaskCheck;
use Throwable;

/** VM power never substitutes for fresh native readiness on the private Gateway. */
final readonly class SandboxTopologyAdmission implements TaskTopologyAdmission
{
    public function __construct(private ExpandTaskSandboxAction $expand, private TaskWorkspaceExecutor $guest, private SandboxWorkloadRuntime $workloads, private TaskSandboxDrivers $drivers) {}

    public function prepare(Task $group, Task $task): void
    {
        $roles = TaskTopology::from($task->topology ?? []);
        if ($group->task_compute !== TaskCompute::Vm || $group->project->slug !== 'orbit') {
            if ($roles !== []) {
                throw new TaskCapacityException(false, 'Declared topology requires an Orbit VM group.');
            }

            return;
        }
        $workspace = $group->taskable;
        if (! $workspace instanceof Instance || $task->parent_id !== $group->id) {
            throw new TaskCapacityException(false, 'The declared topology has no owned task workspace.');
        }
        try {
            $sandbox = $workspace->taskSandbox?->fresh();
            if ($sandbox === null || ! is_array($sandbox->spec['images'] ?? null)) {
                throw new ComputeException('compute.topology_unavailable', 'The declared topology has no recorded inventory.');
            }
            if (TaskCheck::query()->where('task_sandbox_id', $sandbox->id)->where('status', TaskCheckStatus::Running->value)->exists()) {
                throw new ComputeException('compute.topology_unavailable', 'A task check is still running in the sandbox.');
            }
            if ($roles !== [] || array_intersect(TaskTopology::Roles, array_keys($sandbox->spec['images'])) !== []) {
                $missing = array_diff($roles, array_keys($sandbox->spec['images']));
                if ($missing === [] && $sandbox->state === SandboxState::Running) {
                    $sandbox = $this->drivers->forSandbox($sandbox)->observe($sandbox);
                }
                if ($missing !== [] || $sandbox->state !== SandboxState::Running) {
                    $sandbox = $this->expand->execute($workspace, $roles);
                }
            }
            $images = $sandbox->spec['images'] ?? null;
            if (! is_array($images)) {
                throw new ComputeException('compute.topology_unavailable', 'The declared topology has no recorded inventory.');
            }
            $inventory = array_values(array_filter(['gateway', 'operator', ...TaskTopology::Roles], fn (string $role): bool => array_key_exists($role, $images)));
            if (count($inventory) !== count($images) || ! in_array('gateway', $inventory, true) || ! in_array('operator', $inventory, true)) {
                throw new ComputeException('compute.topology_unavailable', 'The declared topology has invalid recorded roles.');
            }
            $script = file_get_contents(resource_path('compute/guest-pair-runtime.py'));
            if (! is_string($script)) {
                throw new ComputeException('compute.topology_unavailable', 'The topology readiness program is unavailable.');
            }
            $request = ['sandbox_id' => $sandbox->id, 'branch' => 'task-'.$group->id, 'phase' => 'inspect', 'inventory' => $inventory];
            $result = $this->guest->execute($workspace, new RemoteCommand(['python3', '-I', '-c', $script],
                input: json_encode($request, JSON_THROW_ON_ERROR), timeout: 90, maxOutputBytes: 8192), 'sandbox-topology', 'tasks.topology_failed');
            $head = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
            if ($result->truncated || ! is_array($head) || ($head['sandbox_id'] ?? null) !== $sandbox->id
                || ! is_string($head['head'] ?? null) || preg_match('/\A[a-f0-9]{40}(?:[a-f0-9]{24})?\z/D', $head['head']) !== 1 || ($head['ready'] ?? null) !== true) {
                throw new ComputeException('compute.topology_unavailable', 'The topology source did not confirm ownership.');
            }
            $request = [...$request, 'head' => $head['head']];
            $result = $this->guest->execute($workspace, new RemoteCommand(['python3', '-I', '-c', $script],
                input: json_encode([...$request, 'phase' => 'version'], JSON_THROW_ON_ERROR), timeout: 90, maxOutputBytes: 8192), 'sandbox-topology', 'tasks.topology_failed');
            $version = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
            if ($result->truncated || ! is_array($version) || ($version['sandbox_id'] ?? null) !== $sandbox->id
                || ($version['head'] ?? null) !== $head['head'] || ($version['ready'] ?? null) !== true
                || ($version['gateway_url'] ?? null) !== 'https://10.44.0.1' || ! is_string($version['gateway_head'] ?? null)
                || preg_match('/\A[a-f0-9]{40}(?:[a-f0-9]{24})?\z/D', $version['gateway_head']) !== 1) {
                throw new ComputeException('compute.topology_unavailable', 'The private Gateway did not confirm its branch version.');
            }
            if ($version['gateway_head'] !== $head['head']) {
                $result = $this->guest->execute($workspace, new RemoteCommand(['python3', '-I', '-c', $script],
                    input: json_encode([...$request, 'phase' => 'gateway'], JSON_THROW_ON_ERROR), timeout: 900, maxOutputBytes: 8192), 'sandbox-topology', 'tasks.topology_failed', role: 'gateway');
                $refreshed = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
                if ($result->truncated || ! is_array($refreshed) || ($refreshed['sandbox_id'] ?? null) !== $sandbox->id
                    || ($refreshed['head'] ?? null) !== $head['head'] || ($refreshed['ready'] ?? null) !== true) {
                    throw new ComputeException('compute.topology_unavailable', 'The private Gateway could not refresh its branch runtime.');
                }
            }
            $this->workloads->prepare($workspace, $head['head'], $inventory);
            if ($version['gateway_head'] !== $head['head']) {
                $result = $this->guest->execute($workspace, new RemoteCommand(['python3', '-I', '-c', $script],
                    input: json_encode([...$request, 'phase' => 'prerequisites'], JSON_THROW_ON_ERROR), timeout: 900, maxOutputBytes: 8192), 'sandbox-topology', 'tasks.topology_failed', role: 'gateway');
                $prerequisites = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
                if ($result->truncated || ! is_array($prerequisites) || ($prerequisites['sandbox_id'] ?? null) !== $sandbox->id
                    || ($prerequisites['head'] ?? null) !== $head['head'] || ($prerequisites['ready'] ?? null) !== true) {
                    throw new ComputeException('compute.topology_unavailable', 'The private Gateway could not refresh native pair prerequisites.');
                }
            }
            $request = [...$request, 'phase' => 'operator', 'doctor' => true];
            $result = $this->guest->execute($workspace, new RemoteCommand(['python3', '-I', '-c', $script],
                input: json_encode($request, JSON_THROW_ON_ERROR), timeout: 900, maxOutputBytes: 8192), 'sandbox-topology', 'tasks.topology_failed');
            $report = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
            if ($result->truncated || ! is_array($report) || ($report['sandbox_id'] ?? null) !== $sandbox->id
                || ($report['head'] ?? null) !== $head['head'] || ($report['ready'] ?? null) !== true
                || ($report['gateway_url'] ?? null) !== 'https://10.44.0.1' || ($report['doctor_nodes'] ?? null) !== $inventory) {
                throw new ComputeException('compute.topology_unavailable', 'The private Gateway did not confirm fresh topology readiness.');
            }
        } catch (ComputeException $exception) {
            throw new TaskCapacityException(false, 'Sandbox topology: '.$exception->getMessage());
        } catch (Throwable) {
            throw new TaskCapacityException(false, 'The sandbox topology is not ready on its private Gateway. Its owned resources remain available for retry.');
        }
    }
}
