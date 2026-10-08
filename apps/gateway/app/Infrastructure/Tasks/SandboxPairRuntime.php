<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\Compute\ComputeException;
use App\Domain\Instances\InstanceState;
use App\Domain\Tasks\TaskTopology;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;
use App\Models\TaskSandbox;
use Throwable;

/** Prepare only the isolated Orbit pair; the live Gateway and fleet are never targets. */
final readonly class SandboxPairRuntime
{
    public function __construct(private TaskWorkspaceExecutor $guest, private SandboxWorkloadRuntime $workloads) {}

    public function prepare(Instance $workspace): void
    {
        $sandbox = $workspace->taskSandbox?->fresh();
        $subnet = $sandbox?->spec['subnet'] ?? null;
        if ($sandbox === null || $sandbox->provider !== 'incus' || $sandbox->group?->project?->slug !== 'orbit'
            || $workspace->checkout_path !== '/home/orbit/orbit' || ! is_array($sandbox->spec['source_template'] ?? null)
            || ! is_array($sandbox->spec['images'] ?? null) || ! is_string($sandbox->spec['images']['operator'] ?? null) || ! is_string($sandbox->spec['images']['gateway'] ?? null)
            || array_diff(array_keys($sandbox->spec['images']), ['operator', 'gateway', ...TaskTopology::Roles]) !== []
            || array_any($sandbox->spec['images'], fn (mixed $image): bool => ! is_string($image) || preg_match('/\A[a-f0-9]{64}\z/D', $image) !== 1)
            || ! in_array($workspace->status, [InstanceState::SourceResolved, InstanceState::Active], true)
            || ! is_string($subnet) || preg_match('/\A10\.233\.([0-9]{1,3})\.0\/24\z/D', $subnet, $match) !== 1 || (int) $match[1] > 255) {
            throw new ComputeException('compute.pair_unavailable', 'The sandbox has no prepared Orbit pair source.');
        }
        $gateway = '10.233.'.$match[1].'.11';
        $operator = '10.233.'.$match[1].'.10';
        $inventory = array_values(array_filter(['gateway', 'operator', ...TaskTopology::Roles], fn (string $role): bool => array_key_exists($role, $sandbox->spec['images'])));
        $addresses = ['gateway' => $gateway, 'operator' => $operator];
        foreach (['app-dev' => 12, 'app-prod' => 13, 'app-prod-2' => 14] as $role => $suffix) {
            if (in_array($role, $inventory, true)) {
                $addresses[$role] = '10.233.'.$match[1].'.'.$suffix;
            }
        }
        $peers = array_values(array_diff($inventory, ['gateway']));
        $request = ['sandbox_id' => $sandbox->id, 'branch' => 'task-'.$sandbox->group_id,
            ...(count($inventory) === 2 ? [] : ['inventory' => $inventory])];
        $step = 'source ownership';
        try {
            $head = $this->runtime($workspace, [...$request, 'phase' => 'inspect'], 'operator');
            $request['head'] = $head;
            $step = 'Gateway public DNS';
            $this->prepareDns($workspace, $sandbox);
            if (count($inventory) > 2) {
                $step = 'Gateway branch runtime';
                $this->runtime($workspace, [...$request, 'phase' => 'gateway'], 'gateway');
                $step = 'workload enrollment';
                $this->workloads->prepare($workspace, $head, array_values($inventory));
            }
            $step = 'Gateway identity';
            $result = $this->guest->execute($workspace, new RemoteCommand(
                ['php', '/dev/stdin', '/home/orbit/.orbit/gateway.sqlite', ...(count($inventory) === 2 ? [$gateway, $operator] : [json_encode($addresses, JSON_THROW_ON_ERROR)])],
                input: $this->program('retarget-gateway.php'), timeout: 30, maxOutputBytes: 8192,
            ), 'sandbox-pair', 'tasks.pair_setup_failed', role: 'gateway');
            $endpoints = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
            if ($result->truncated || ! is_array($endpoints) || array_keys($endpoints) !== $peers
                || array_any($endpoints, fn (mixed $endpoint): bool => ! is_string($endpoint)
                    || preg_match('/\A'.preg_quote($gateway, '/').':([1-9][0-9]{0,4})\z/D', $endpoint, $port) !== 1
                    || (int) $port[1] > 65535)) {
                throw new ComputeException('compute.pair_unavailable', 'The test Gateway endpoint is invalid.');
            }
            foreach ($peers as $peer) {
                $endpoint = $endpoints[$peer];
                if (! is_string($endpoint)) {
                    throw new ComputeException('compute.pair_unavailable', 'The test Gateway endpoint is invalid.');
                }
                $step = $peer.' VPN';
                $this->guest->execute($workspace, new RemoteCommand(
                    ['sudo', '-n', 'bash', '-seu', '--', $gateway, $endpoint],
                    input: $this->program('retarget-vpn.sh'), timeout: 90, maxOutputBytes: 8192,
                ), 'sandbox-pair', 'tasks.pair_setup_failed', role: $peer);
            }
            if (count($inventory) === 2) {
                $step = 'Gateway branch runtime';
                $this->runtime($workspace, [...$request, 'phase' => 'gateway'], 'gateway');
            }
            $step = 'native pair prerequisites';
            $this->runtime($workspace, [...$request, 'phase' => 'prerequisites'], 'gateway');
            $step = 'operator CLI readiness';
            $this->runtime($workspace, [...$request, 'phase' => 'operator'], 'operator');
        } catch (Throwable) {
            throw new ComputeException('compute.pair_unavailable', 'The sandbox pair could not confirm '.$step.'.');
        }
    }

    private function prepareDns(Instance $workspace, TaskSandbox $sandbox): void
    {
        $template = $sandbox->spec['source_template'] ?? null;
        if (! is_array($template) || ! is_string($template['repository'] ?? null) || ! is_string($template['base'] ?? null)) {
            throw new ComputeException('compute.pair_unavailable', 'The test Gateway source identity is unavailable.');
        }
        $request = ['sandbox_id' => $sandbox->id, 'checkout' => $workspace->checkout_path,
            'repository' => $template['repository'], 'branch' => 'task-'.$sandbox->group_id,
            'base' => $template['base'], 'source_template' => $template];
        foreach (['github_dns' => 'guest-github-dns.py', 'pair_dns' => 'guest-pair-dns.py'] as $operation => $name) {
            $result = $this->guest->execute($workspace, new RemoteCommand(
                ['sudo', '-n', 'python3', '-I', '-c', $this->program($name)],
                input: json_encode(['operation' => $operation, ...$request], JSON_THROW_ON_ERROR), timeout: 90, maxOutputBytes: 8192,
            ), 'sandbox-pair', 'tasks.pair_setup_failed', role: 'gateway');
            if ($result->truncated || json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR) !== ['ready' => true]) {
                throw new ComputeException('compute.pair_unavailable', 'The test Gateway public DNS is unavailable.');
            }
        }
    }

    /** @param array<string, mixed> $request */
    private function runtime(Instance $workspace, array $request, string $role): string
    {
        $result = $this->guest->execute($workspace, new RemoteCommand(
            ['python3', '-I', '-c', $this->program('guest-pair-runtime.py')],
            input: json_encode($request, JSON_THROW_ON_ERROR), timeout: $role === 'gateway' ? 900 : 90, maxOutputBytes: 8192,
        ), 'sandbox-pair', 'tasks.pair_setup_failed', role: $role);
        $data = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
        if ($result->truncated || ! is_array($data) || ($data['ready'] ?? null) !== true
            || ($data['sandbox_id'] ?? null) !== $request['sandbox_id'] || ! is_string($data['head'] ?? null)
            || preg_match('/\A[a-f0-9]{40}(?:[a-f0-9]{24})?\z/D', $data['head']) !== 1
            || (isset($request['head']) && $data['head'] !== $request['head'])) {
            throw new ComputeException('compute.pair_unavailable', 'The sandbox branch runtime did not confirm readiness.');
        }

        return $data['head'];
    }

    private function program(string $name): string
    {
        $program = file_get_contents(resource_path('compute/'.$name));
        if (! is_string($program)) {
            throw new ComputeException('compute.pair_unavailable', 'The sandbox pair program is unavailable.');
        }

        return $program;
    }
}
