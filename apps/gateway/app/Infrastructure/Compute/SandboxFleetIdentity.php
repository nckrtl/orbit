<?php

declare(strict_types=1);

namespace App\Infrastructure\Compute;

use App\Domain\Clusters\ClusterState;
use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxSpec;
use App\Domain\Compute\SandboxState;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\WireGuard\WireGuardAddressAllocator;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\TaskSandbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Reserve both sides of ownership before the first remote fleet mutation. */
final readonly class SandboxFleetIdentity
{
    public function __construct(private WireGuardAddressAllocator $addresses) {}

    public function reserve(TaskSandbox $sandbox): Node
    {
        return DB::transaction(function () use ($sandbox): Node {
            $sandbox->refresh();
            $this->assertActive($sandbox);
            if ($sandbox->enrollment !== null) {
                $node = Node::query()->find($sandbox->node_id);
                if (! $node instanceof Node) {
                    throw $this->ownership();
                }
                $this->assertOwned($sandbox, $node);

                return $node;
            }
            if ($sandbox->node_id !== null || Node::query()->where('compute_sandbox_id', $sandbox->id)->exists()
                || Node::query()->where('name', $sandbox->name)->exists()) {
                throw $this->ownership();
            }
            $spec = SandboxSpec::fromArray($sandbox->spec);
            $clusterId = config('compute.upcloud.dev_cluster_id');
            $cluster = is_int($clusterId) ? Cluster::query()->find($clusterId) : null;
            $gateway = $this->role(RoleName::Gateway);
            $hub = $this->role(RoleName::Vpn);
            $router = $cluster?->routerAssignment?->node;
            $model = config('compute.upcloud.model_address');
            $port = config('compute.upcloud.model_port');
            if (! $cluster instanceof Cluster || $cluster->state !== ClusterState::Active || ! $router instanceof Node
                || $router->status !== LifecycleStatus::Active || ! $this->address($model)
                || ! is_int($port) || $port < 1 || $port > 65535
                || $gateway->public_ssh_host !== $spec->gatewayAddress || $hub->public_ssh_host !== $spec->wireguardAddress
                || ! $this->address($gateway->wireguard_ip) || ! $this->address($hub->wireguard_ip) || ! $this->address($router->wireguard_ip)) {
                throw $this->configuration();
            }
            $modelNode = Node::query()->where('wireguard_ip', $model)->where('status', LifecycleStatus::Active)->first();
            if (! $modelNode instanceof Node) {
                throw $this->configuration();
            }
            $node = new Node([
                'name' => $sandbox->name, 'cluster_id' => $cluster->id, 'status' => LifecycleStatus::Provisioning,
                'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => $sandbox->public_address,
                'user' => 'orbit', 'wireguard_ip' => $this->addresses->next(),
            ]);
            if (! $this->address($node->wireguard_ip)) {
                throw $this->configuration();
            }
            $node->compute_sandbox_id = $sandbox->id;
            $node->save();
            $sandbox->node_id = $node->id;
            $sandbox->enrollment = [
                'node_id' => $node->id, 'cluster_id' => $cluster->id, 'hub_id' => $hub->id,
                'gateway_id' => $gateway->id, 'router_id' => $router->id, 'model_node_id' => $modelNode->id,
                'hub_address' => $hub->wireguard_ip, 'gateway_address' => $gateway->wireguard_ip,
                'router_address' => $router->wireguard_ip, 'model_address' => $model, 'model_port' => $port,
                'wireguard_ip' => $node->wireguard_ip, 'public_address' => $sandbox->public_address,
            ];
            $sandbox->save();

            return $node;
        });
    }

    public function assertActive(TaskSandbox $sandbox): void
    {
        $group = $sandbox->group;
        if (! $sandbox->exists || ! Str::isUuid($sandbox->id) || $sandbox->provider !== 'upcloud'
            || $sandbox->name !== 'orbit-sandbox-'.$sandbox->id || $sandbox->state !== SandboxState::Running
            || $sandbox->desired_power !== 'running' || $sandbox->server_id === null || $sandbox->disk_id === null
            || filter_var($sandbox->public_address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            || $group === null || $group->task_compute !== TaskCompute::Vm || $group->project->slug === 'orbit'
            || $group->taskable_id !== null || in_array($group->status, [TaskGroupStatus::Completed, TaskGroupStatus::Cancelled, TaskGroupStatus::Failed], true)) {
            throw $this->ownership();
        }
        $group->requireManagedExecution();
    }

    public function assertOwned(TaskSandbox $sandbox, Node $node): void
    {
        $e = $sandbox->enrollment;
        if ($e === null || $sandbox->node_id !== $node->id || ($e['node_id'] ?? null) !== $node->id
            || $node->compute_sandbox_id !== $sandbox->id || $node->name !== $sandbox->name
            || $node->cluster_id !== ($e['cluster_id'] ?? null) || $node->user !== 'orbit'
            || $node->wireguard_ip !== ($e['wireguard_ip'] ?? null) || ! $this->address($node->wireguard_ip)
            || $node->public_ssh_host !== ($e['public_address'] ?? null) || $node->public_ssh_host !== $sandbox->public_address
            || $node->public_ssh_port !== 22 || $node->platform !== 'linux' || $node->architecture !== 'x86_64'
            || $node->accessibleNodes()->whereKeyNot($node->id)->exists()
            || $node->roles()->where('role', '!=', RoleName::AppDev->value)->exists()) {
            throw $this->ownership();
        }
        if (isset($e['ssh_fingerprint']) && $node->ssh_host_fingerprint !== $e['ssh_fingerprint']) {
            throw $this->ownership();
        }
        foreach (['hub', 'gateway', 'router', 'model_node'] as $kind) {
            $peerId = $e[$kind.'_id'] ?? null;
            $peer = is_int($peerId) ? Node::query()->find($peerId) : null;
            $key = $kind === 'model_node' ? 'model_address' : $kind.'_address';
            if (! $peer instanceof Node || $peer->status !== LifecycleStatus::Active || ! $this->address($peer->wireguard_ip)
                || $peer->wireguard_ip !== ($e[$key] ?? null)) {
                throw $this->configuration();
            }
        }
        if (($e['hub_id'] ?? null) !== $this->role(RoleName::Vpn)->id
            || ($e['gateway_id'] ?? null) !== $this->role(RoleName::Gateway)->id
            || Cluster::query()->find($node->cluster_id)?->routerAssignment?->node_id !== ($e['router_id'] ?? null)) {
            throw $this->configuration();
        }
    }

    /** Attached workspaces use this check without repeating initial enrollment. */
    public function assertReady(TaskSandbox $sandbox, Node $node): void
    {
        $this->assertOwned($sandbox, $node);
        if ($sandbox->provider !== 'upcloud' || $sandbox->state !== SandboxState::Running || $sandbox->desired_power !== 'running'
            || $sandbox->network_policy !== 'sealed' || ! is_string($sandbox->enrollment['hub_confirmed_at'] ?? null)
            || $sandbox->enrolled_at === null || $node->status !== LifecycleStatus::Active
            || ! $node->roles()->where('role', RoleName::AppDev->value)->where('status', LifecycleStatus::Active)->exists()) {
            throw new ComputeException('compute.enrollment_not_ready', 'The owned sandbox Node is not ready.');
        }
    }

    private function role(RoleName $role): Node
    {
        $nodes = Node::query()->where('status', LifecycleStatus::Active)
            ->whereHas('roles', fn ($q) => $q->where('role', $role->value)->where('status', LifecycleStatus::Active))->get();
        if ($nodes->count() !== 1) {
            throw $this->configuration();
        }

        return $nodes->firstOrFail();
    }

    private function address(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, '10.44.') && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    private function ownership(): ComputeException
    {
        return new ComputeException('compute.ownership_mismatch', 'The sandbox fleet identity does not match its reservation.');
    }

    private function configuration(): ComputeException
    {
        return new ComputeException('compute.fleet_unavailable', 'The recorded sandbox fleet endpoints are unavailable.');
    }
}
