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
use App\Domain\WireGuard\VpnSettings;
use App\Domain\WireGuard\WireGuardAddressAllocator;
use App\Domain\WireGuard\WireGuardEndpoint;
use App\Infrastructure\Ssh\HostKey;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\TaskSandbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Reserve both sides of ownership before the first remote fleet mutation. */
final readonly class SandboxFleetIdentity
{
    public function __construct(private WireGuardAddressAllocator $addresses, private VpnSettings $vpn) {}

    public function reserve(TaskSandbox $sandbox, ?HostKey $key = null): Node
    {
        return DB::transaction(function () use ($sandbox, $key): Node {
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
            $local = $sandbox->provider === 'incus';
            $bootstrap = $local ? $this->incusBootstrap($sandbox) : null;
            $spec = $local ? null : SandboxSpec::fromArray($sandbox->spec);
            if ($local && ($key === null || $key->type !== 'ssh-ed25519')) {
                throw $this->ownership();
            }
            $clusterId = config('compute.'.$sandbox->provider.'.dev_cluster_id');
            $cluster = is_int($clusterId) ? Cluster::query()->find($clusterId) : null;
            $gateway = $this->role(RoleName::Gateway);
            $hub = $this->role(RoleName::Vpn);
            $router = $cluster?->routerAssignment?->node;
            $model = config('compute.'.$sandbox->provider.'.model_address');
            $port = config('compute.'.$sandbox->provider.'.model_port');
            if (! $cluster instanceof Cluster || $cluster->state !== ClusterState::Active || ! $router instanceof Node
                || $router->status !== LifecycleStatus::Active || ! $this->address($model)
                || ! is_int($port) || $port < 1 || $port > 65535
                || ($bootstrap !== null ? $gateway->wireguard_ip !== $bootstrap['gateway_address'] : $gateway->public_ssh_host !== $spec?->gatewayAddress)
                || $hub->public_ssh_host !== ($bootstrap !== null ? $bootstrap['wireguard_address'] : $spec->wireguardAddress)
                || ! $this->address($gateway->wireguard_ip) || ! $this->address($hub->wireguard_ip) || ! $this->address($router->wireguard_ip)) {
                throw $this->configuration();
            }
            $modelNode = Node::query()->where('wireguard_ip', $model)->where('status', LifecycleStatus::Active)->first();
            if (! $modelNode instanceof Node) {
                throw $this->configuration();
            }
            if ($bootstrap !== null) {
                $this->assertHubEndpoint($hub, $bootstrap);
            }
            $node = new Node([
                'name' => $sandbox->name, 'cluster_id' => $cluster->id, 'status' => LifecycleStatus::Provisioning,
                'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => $bootstrap !== null ? $bootstrap['ssh_host'] : $sandbox->public_address,
                'public_ssh_port' => $bootstrap !== null ? $bootstrap['ssh_port'] : 22,
                'user' => 'orbit', 'wireguard_ip' => $this->addresses->next(),
            ]);
            if (! $this->address($node->wireguard_ip)) {
                throw $this->configuration();
            }
            if ($key !== null && $local) {
                $node->fill(['ssh_host_key_type' => $key->type, 'ssh_host_key' => $key->value, 'ssh_host_fingerprint' => $key->fingerprint]);
            }
            $node->compute_sandbox_id = $sandbox->id;
            $node->save();
            $sandbox->node_id = $node->id;
            $localEnrollment = [];
            if ($bootstrap !== null) {
                $localEnrollment = ['ssh_port' => $node->public_ssh_port, 'incus_spec' => $sandbox->spec,
                    'ssh_fingerprint' => $key->fingerprint, 'ssh_key_type' => $key->type, 'ssh_key' => $key->value];
            }
            $sandbox->enrollment = [
                'node_id' => $node->id, 'cluster_id' => $cluster->id, 'hub_id' => $hub->id,
                'gateway_id' => $gateway->id, 'router_id' => $router->id, 'model_node_id' => $modelNode->id,
                'hub_address' => $hub->wireguard_ip, 'gateway_address' => $gateway->wireguard_ip,
                'router_address' => $router->wireguard_ip, 'model_address' => $model, 'model_port' => $port,
                'wireguard_ip' => $node->wireguard_ip, 'public_address' => $node->public_ssh_host,
                ...$localEnrollment,
            ];
            $sandbox->save();

            return $node;
        });
    }

    public function assertActive(TaskSandbox $sandbox): void
    {
        $group = $sandbox->group;
        if (! $sandbox->exists || ! Str::isUuid($sandbox->id) || ! in_array($sandbox->provider, ['upcloud', 'incus'], true)
            || ($sandbox->provider === 'upcloud' && $sandbox->name !== 'orbit-sandbox-'.$sandbox->id) || $sandbox->state !== SandboxState::Running
            || $sandbox->desired_power !== 'running'
            || ($sandbox->provider === 'upcloud' && ($sandbox->server_id === null || $sandbox->disk_id === null
                || filter_var($sandbox->public_address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false))
            || $group === null || $group->task_compute !== TaskCompute::Vm || $group->project->slug === 'orbit'
            || $group->taskable_id !== null || in_array($group->status, [TaskGroupStatus::Completed, TaskGroupStatus::Cancelled, TaskGroupStatus::Failed], true)) {
            throw $this->ownership();
        }
        $group->requireManagedExecution();
        if ($sandbox->provider === 'incus') {
            $this->incusBootstrap($sandbox);
        }
    }

    public function assertOwned(TaskSandbox $sandbox, Node $node): void
    {
        $e = $sandbox->enrollment;
        $local = $sandbox->provider === 'incus';
        $bootstrap = $local ? $this->incusBootstrap($sandbox) : null;
        if ($e === null || $sandbox->node_id !== $node->id || ($e['node_id'] ?? null) !== $node->id
            || $node->compute_sandbox_id !== $sandbox->id || $node->name !== $sandbox->name
            || $node->cluster_id !== ($e['cluster_id'] ?? null) || $node->user !== 'orbit'
            || $node->wireguard_ip !== ($e['wireguard_ip'] ?? null) || ! $this->address($node->wireguard_ip)
            || $node->public_ssh_host !== ($e['public_address'] ?? null)
            || $node->public_ssh_host !== ($bootstrap !== null ? $bootstrap['ssh_host'] : $sandbox->public_address)
            || $node->public_ssh_port !== ($bootstrap !== null ? $bootstrap['ssh_port'] : 22)
            || ($local && (($e['incus_spec'] ?? null) !== $sandbox->spec || ($e['ssh_port'] ?? null) !== $node->public_ssh_port
                || ($e['ssh_key_type'] ?? null) !== $node->ssh_host_key_type || ($e['ssh_key'] ?? null) !== $node->ssh_host_key
                || ! is_string($e['ssh_fingerprint'] ?? null))) || $node->platform !== 'linux' || $node->architecture !== 'x86_64'
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
            if ($kind === 'hub' && $bootstrap !== null) {
                $this->assertHubEndpoint($peer, $bootstrap);
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
        if (! in_array($sandbox->provider, ['upcloud', 'incus'], true) || $sandbox->state !== SandboxState::Running || $sandbox->desired_power !== 'running'
            || $sandbox->network_policy !== 'sealed' || ! is_string($sandbox->enrollment['hub_confirmed_at'] ?? null)
            || $sandbox->enrolled_at === null || $node->status !== LifecycleStatus::Active
            || ! $node->roles()->where('role', RoleName::AppDev->value)->where('status', LifecycleStatus::Active)->exists()) {
            throw new ComputeException('compute.enrollment_not_ready', 'The owned sandbox Node is not ready.');
        }
    }

    /** @return array{ssh_host: string, ssh_port: int, gateway_address: string, wireguard_address: string, wireguard_port: int} */
    public function incusBootstrap(TaskSandbox $sandbox): array
    {
        $spec = $sandbox->spec;
        $bootstrap = $spec['project_bootstrap'] ?? null;
        $subnet = $spec['subnet'] ?? null;
        $host = is_int($spec['host_id'] ?? null) ? Node::query()->find($spec['host_id']) : null;
        if ($sandbox->provider !== 'incus' || $sandbox->server_id !== null || $sandbox->disk_id !== null || $sandbox->public_address !== null
            || $sandbox->name !== 'ot-'.substr(hash('sha256', $sandbox->id), 0, 10)
            || ! $host instanceof Node || $host->status !== LifecycleStatus::Active || $host->platform !== 'linux'
            || ! is_string($spec['project'] ?? null) || preg_match('/\Aorbit-(?:task-sandboxes|sandbox-proof-[a-z0-9]+)\z/D', $spec['project']) !== 1
            || ! is_string($spec['pool'] ?? null) || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,62}\z/D', $spec['pool']) !== 1
            || ! is_array($spec['images'] ?? null) || array_keys($spec['images']) !== ['operator']
            || ! is_string($spec['images']['operator']) || preg_match('/\A[a-f0-9]{64}\z/D', $spec['images']['operator']) !== 1
            || ($spec['project_slug'] ?? null) !== $sandbox->group?->project->slug || ($spec['project_slug'] ?? null) === 'orbit'
            || isset($spec['source_template']) || isset($spec['pi_host']) || isset($spec['pi_port']) || isset($spec['gateway_address'])
            || ! is_string($subnet) || preg_match('/\A10\.233\.([0-9]{1,3})\.0\/24\z/D', $subnet, $parts) !== 1
            || (int) $parts[1] < 1 || (int) $parts[1] > 254 || $subnet !== '10.233.'.(int) $parts[1].'.0/24'
            || ! is_array($bootstrap) || count($bootstrap) !== 5
            || ! is_string($bootstrap['ssh_host'] ?? null) || ! $this->address($bootstrap['ssh_host']) || $bootstrap['ssh_host'] !== $host->wireguard_ip
            || ($bootstrap['ssh_port'] ?? null) !== 24000 + (int) $parts[1]
            || ! is_string($bootstrap['gateway_address'] ?? null) || ! $this->address($bootstrap['gateway_address']) || $bootstrap['gateway_address'] === $host->wireguard_ip
            || ! is_string($bootstrap['wireguard_address'] ?? null)
            || filter_var($bootstrap['wireguard_address'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            || ! is_int($bootstrap['wireguard_port'] ?? null) || $bootstrap['wireguard_port'] < 1 || $bootstrap['wireguard_port'] > 65535) {
            throw $this->ownership();
        }

        return ['ssh_host' => $bootstrap['ssh_host'], 'ssh_port' => $bootstrap['ssh_port'],
            'gateway_address' => $bootstrap['gateway_address'], 'wireguard_address' => $bootstrap['wireguard_address'],
            'wireguard_port' => $bootstrap['wireguard_port']];
    }

    /** @param array{wireguard_address: string, wireguard_port: int} $bootstrap */
    private function assertHubEndpoint(Node $hub, array $bootstrap): void
    {
        $port = filter_var($this->vpn->port(), FILTER_VALIDATE_INT);
        if (! is_int($port) || $port !== $bootstrap['wireguard_port']
            || ($this->vpn->endpoint() ?? WireGuardEndpoint::format($hub->public_ssh_host, $port))
                !== WireGuardEndpoint::format($bootstrap['wireguard_address'], $bootstrap['wireguard_port'])) {
            throw $this->configuration();
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
