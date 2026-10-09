<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\TaskVms\TaskVmSetupScript;
use App\Models\Cluster;
use App\Models\Node;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;

/**
 * Installs the static task VM filter on the `vpn` Node. Every argument comes from Gateway rows and
 * `TaskVmSettings`, which already holds the range inside the WireGuard subnet. The filter cuts off
 * every address in the range, so the command refuses a range that holds a Node other than a task VM.
 */
final class PrepareTaskVmHubCommand extends Command
{
    /** Reverb is served by the `websocket` Node's Caddy site for `reverb.orbit` on this port. */
    private const int ReverbPort = 443;

    /** Task VM Nodes are named `tvm-<task VM id>`. Only they may hold a reserved address. */
    private const string TaskVmNodeName = '/\Atvm-[0-9]+\z/D';

    #[\Override]
    protected $signature = 'task-vms:prepare-hub';

    #[\Override]
    protected $description = 'Install the static task VM filter for the reserved WireGuard range on the vpn Node.';

    public function handle(VpnSettings $vpn, TaskVmSetupScript $script): int
    {
        try {
            $settings = resolve(TaskVmSettings::class);
            $range = Ipv4Subnet::from($settings->wireguardRange);
            $model = $this->modelProxy($settings->modelProxyOrigin, $range);
            $clusterId = $settings->devClusterId ?? throw $this->invalid('dev_cluster_id is not set.');
            $hub = $this->roleNode(RoleName::Vpn);
            $this->guardRange($range);
            $script->run($hub, TaskVmSetupScript::HubScript, [
                $range->value(),
                $vpn->dnsServer() ?? $this->address($hub),
                $this->address($this->roleNode(RoleName::Gateway)),
                (string) Config::integer('orbit.pi.port'),
                $this->address($this->roleNode(RoleName::WebSocket)).':'.self::ReverbPort,
                $model,
                $this->address($this->router($clusterId)),
            ]);
        } catch (ResourceOperationException $exception) {
            $this->error("[{$exception->errorCode}] {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Hub [{$hub->name}] filters task VM range [{$range->value()}].");

        return self::SUCCESS;
    }

    /** The `ip:port` of the normalized model proxy origin. The host must be an IPv4 address outside the range. */
    private function modelProxy(?string $origin, Ipv4Subnet $range): string
    {
        $parts = parse_url($origin ?? throw $this->invalid('model_proxy_origin is not set.'));
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';

        if (! is_array($parts) || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || $range->contains($host)) {
            throw $this->invalid("model_proxy_origin [{$origin}] must name an IPv4 address outside [{$range->value()}].");
        }

        return $host.':'.($parts['port'] ?? (($parts['scheme'] ?? '') === 'https' ? 443 : 80));
    }

    private function guardRange(Ipv4Subnet $range): void
    {
        $nodes = Node::query()->whereNotNull('wireguard_ip')->get(['name', 'wireguard_ip']);

        foreach ($nodes as $node) {
            if (is_string($node->wireguard_ip) && $range->contains($node->wireguard_ip)
                && preg_match(self::TaskVmNodeName, $node->name) !== 1) {
                throw new TaskVmException('task_vm.range_in_use', "Node [{$node->name}] holds [{$node->wireguard_ip}] inside [{$range->value()}].");
            }
        }
    }

    private function roleNode(RoleName $role): Node
    {
        $nodes = Node::query()
            ->where('status', LifecycleStatus::Active)
            ->whereHas('roles', static function (Builder $query) use ($role): void {
                $query->where('role', $role->value)->where('status', LifecycleStatus::Active);
            })
            ->get();

        if ($nodes->count() !== 1) {
            throw $this->unavailable("Expected exactly one active [{$role->value}] Node, found [{$nodes->count()}].");
        }

        return $nodes->firstOrFail();
    }

    private function router(int $clusterId): Node
    {
        $router = Cluster::query()->find($clusterId)?->routerAssignment?->node;

        if (! $router instanceof Node || $router->status !== LifecycleStatus::Active) {
            throw $this->unavailable("Cluster [{$clusterId}] has no active router Node.");
        }

        return $router;
    }

    private function address(Node $node): string
    {
        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            throw $this->unavailable("Node [{$node->name}] has no WireGuard address.");
        }

        return $node->wireguard_ip;
    }

    private function unavailable(string $message): TaskVmException
    {
        return new TaskVmException('task_vm.fleet_unavailable', $message);
    }

    private function invalid(string $reason): TaskVmException
    {
        return new TaskVmException('task_vm.invalid_config', "The task VM config is invalid: {$reason}", 500);
    }
}
