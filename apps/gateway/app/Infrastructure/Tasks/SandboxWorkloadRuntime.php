<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\Compute\ComputeException;
use App\Domain\Tasks\TaskTopology;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;

/** Fresh guest identities and native enrollment belong only to the group's private Gateway. */
final readonly class SandboxWorkloadRuntime
{
    public function __construct(private TaskWorkspaceExecutor $guest) {}

    /** @param list<string> $inventory */
    public function prepare(Instance $workspace, string $head, array $inventory): void
    {
        $roles = array_values(array_intersect(TaskTopology::Roles, $inventory));
        if ($roles === []) {
            return;
        }
        $sandbox = $workspace->taskSandbox?->fresh();
        if ($sandbox === null || ! is_array($sandbox->spec['source_template'] ?? null) || ! is_string($sandbox->spec['subnet'] ?? null)) {
            throw new ComputeException('compute.topology_unavailable', 'Workload enrollment needs the recorded source and network.');
        }
        $request = ['sandbox_id' => $sandbox->id, 'branch' => 'task-'.$sandbox->group_id, 'head' => $head,
            'inventory' => $inventory, 'source_template' => $sandbox->spec['source_template'], 'subnet' => $sandbox->spec['subnet']];
        $report = $this->phase($workspace, [...$request, 'phase' => 'gateway-identity'], 'gateway');
        $key = $report['gateway_public_key'] ?? null;
        if (! is_string($key) || preg_match('/\Assh-ed25519 [A-Za-z0-9+\/]+={0,2}\z/D', $key) !== 1) {
            throw new ComputeException('compute.topology_unavailable', 'The private Gateway did not confirm its SSH identity.');
        }
        $identities = [];
        foreach ($roles as $role) {
            $identity = $this->phase($workspace, [...$request, 'phase' => 'workload-identity', 'role' => $role, 'gateway_public_key' => $key], $role);
            $fingerprint = $identity['fingerprint'] ?? null;
            $architecture = $identity['architecture'] ?? null;
            if (($identity['role'] ?? null) !== $role || ! in_array($architecture, ['x86_64', 'aarch64'], true)
                || ! is_string($fingerprint) || preg_match('/\ASHA256:[A-Za-z0-9+\/]{43}\z/D', $fingerprint) !== 1) {
                throw new ComputeException('compute.topology_unavailable', 'The workload did not confirm its owned SSH identity.');
            }
            $identities[] = ['role' => $role, 'architecture' => $architecture, 'fingerprint' => $fingerprint];
        }
        foreach ($roles as $role) {
            $report = $this->phase($workspace, [...$request, 'phase' => 'enroll', 'role' => $role, 'identities' => $identities], 'gateway');
            if (($report['enrolled_roles'] ?? null) !== [$role]) {
                throw new ComputeException('compute.topology_unavailable', 'The private Gateway did not confirm the workload role.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function phase(Instance $workspace, array $request, string $role): array
    {
        $program = file_get_contents(resource_path('compute/guest-workload-runtime.py'));
        if (! is_string($program)) {
            throw new ComputeException('compute.topology_unavailable', 'The native workload program is unavailable.');
        }
        $result = $this->guest->execute($workspace, new RemoteCommand(['python3', '-I', '-c', $program],
            input: json_encode($request, JSON_THROW_ON_ERROR), timeout: $request['phase'] === 'enroll' ? 900 : 90, maxOutputBytes: 8192),
            'sandbox-workload', 'tasks.topology_failed', role: $role);
        $report = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        if ($result->truncated || ! is_array($report) || ($report['sandbox_id'] ?? null) !== $request['sandbox_id']
            || ($report['head'] ?? null) !== $request['head'] || ($report['ready'] ?? null) !== true) {
            throw new ComputeException('compute.topology_unavailable', 'The private workload phase did not confirm ownership and readiness.');
        }

        return $report;
    }
}
