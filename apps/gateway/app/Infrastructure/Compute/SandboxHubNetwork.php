<?php

declare(strict_types=1);

namespace App\Infrastructure\Compute;

use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxNetworkPolicy;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;
use App\Models\TaskSandbox;
use Throwable;

final readonly class SandboxHubNetwork implements SandboxNetworkPolicy
{
    public function __construct(private DevelopmentSshExecutor $ssh, private SandboxFleetIdentity $identity) {}

    public function ensure(TaskSandbox $sandbox): void
    {
        $node = Node::query()->find($sandbox->node_id);
        if (! $node instanceof Node) {
            throw new ComputeException('compute.ownership_mismatch', 'The sandbox Node is unavailable.');
        }
        $this->identity->assertOwned($sandbox, $node);
        $this->operate($sandbox, 'ensure');
        $sandbox->enrollment = [...($sandbox->enrollment ?? []), 'hub_confirmed_at' => now()->toIso8601String()];
        $sandbox->save();
    }

    public function remove(TaskSandbox $sandbox): void
    {
        if ($sandbox->node_id !== null || Node::query()->where('compute_sandbox_id', $sandbox->id)->exists()) {
            throw new ComputeException('compute.node_attached', 'Remove the sandbox peer before removing its hub policy.');
        }
        $this->operate($sandbox, 'remove');
    }

    private function operate(TaskSandbox $sandbox, string $operation): void
    {
        try {
            $e = $sandbox->enrollment;
            $hub = is_array($e) && is_int($e['hub_id'] ?? null) ? Node::query()->find($e['hub_id']) : null;
            if (! $hub instanceof Node || $hub->wireguard_ip !== ($e['hub_address'] ?? null)) {
                throw new ComputeException('compute.fleet_unavailable', 'The recorded WireGuard hub is unavailable.');
            }
            $program = file_get_contents(resource_path('compute/sandbox-hub-network.py'));
            if (! is_string($program)) {
                throw new ComputeException('compute.network_unavailable', 'The sandbox hub policy program is unavailable.');
            }
            $request = ['operation' => $operation, 'sandbox_id' => $sandbox->id, 'address' => $e['wireguard_ip'],
                'hub' => $e['hub_address'], 'gateway' => $e['gateway_address'], 'router' => $e['router_address'],
                'model' => $e['model_address'], 'model_port' => $e['model_port']];
            $result = $this->ssh->execute($hub, new RemoteCommand(['sudo', '-n', 'python3', '-I', '-c', $program],
                protectedInput: ProtectedInput::fromString(json_encode($request, JSON_THROW_ON_ERROR)), timeout: 120, maxOutputBytes: 2048),
                'sandbox-network', 'compute.network_unavailable', 125);
            if ($result->truncated || json_decode($result->stdout, true) !== ['sandbox_id' => $sandbox->id, 'operation' => $operation, 'confirmed' => true]) {
                throw new ComputeException('compute.network_unavailable', 'The hub did not confirm the sandbox policy.');
            }
        } catch (ComputeException $e) {
            throw $e;
        } catch (Throwable) {
            throw new ComputeException('compute.network_unavailable', 'The hub did not confirm the sandbox policy.');
        }
    }
}
