<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmNetworkConfig;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\TaskVms\TaskVmSetupScript;
use App\Models\Cluster;
use App\Models\Node;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Installs the static task VM filter on the `vpn` Node. Every argument comes from Gateway rows and
 * settings. The filter matches only the reserved range, so the command refuses a range that holds a
 * fleet Node or an endpoint, or that lies outside the WireGuard subnet.
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

    public function handle(TaskVmNetworkConfig $config, VpnSettings $vpn, TaskVmSetupScript $script): int
    {
        try {
            $subnet = Ipv4Subnet::from($vpn->subnet());
            $range = $config->wireguardRange();
            $hub = $this->roleNode(RoleName::Vpn);
            $gateway = $this->address($this->roleNode(RoleName::Gateway));
            $reverb = $this->address($this->roleNode(RoleName::WebSocket));
            $model = $config->modelProxyEndpoint();
            $router = $this->address($this->router($config->devClusterId()));
            $this->guardRange($range, $subnet, [$gateway, $reverb, $model['host'], $router]);
            $arguments = [
                $range->value(),
                $gateway,
                $reverb.':'.self::ReverbPort,
                $model['host'].':'.$model['port'],
                $router,
            ];
            $script->run($hub, TaskVmSetupScript::HubScript, $arguments);
        } catch (ResourceOperationException|InvalidArgumentException $exception) {
            $code = $exception instanceof ResourceOperationException ? $exception->errorCode : 'vpn.subnet_invalid';
            $this->error("[{$code}] {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Hub [{$hub->name}] filters task VM range [{$range->value()}].");

        return self::SUCCESS;
    }

    /** @param  list<string>  $endpoints */
    private function guardRange(Ipv4Subnet $range, Ipv4Subnet $subnet, array $endpoints): void
    {
        if ($range->prefixLength() < $subnet->prefixLength() || ! $subnet->contains($range->networkAddress())) {
            throw new ResourceOperationException(
                'task_vm.range_outside_vpn_subnet',
                "Range [{$range->value()}] is not inside the WireGuard subnet [{$subnet->value()}].",
                409,
            );
        }

        foreach ($endpoints as $endpoint) {
            if (! $subnet->containsUsableAddress($endpoint) || $range->contains($endpoint)) {
                throw $this->rangeInUse("Endpoint [{$endpoint}] must be a fleet address outside [{$range->value()}].");
            }
        }

        $nodes = Node::query()->whereNotNull('wireguard_ip')->get(['name', 'wireguard_ip']);

        foreach ($nodes as $node) {
            if (is_string($node->wireguard_ip) && $range->contains($node->wireguard_ip)
                && preg_match(self::TaskVmNodeName, $node->name) !== 1) {
                throw $this->rangeInUse("Node [{$node->name}] holds [{$node->wireguard_ip}] inside [{$range->value()}].");
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
            throw new ResourceOperationException(
                'task_vm.fleet_unavailable',
                "Expected exactly one active [{$role->value}] Node, found [{$nodes->count()}].",
                409,
            );
        }

        return $nodes->firstOrFail();
    }

    private function router(int $clusterId): Node
    {
        $router = Cluster::query()->find($clusterId)?->routerAssignment?->node;

        if (! $router instanceof Node || $router->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException(
                'task_vm.fleet_unavailable',
                "Cluster [{$clusterId}] has no active router Node.",
                409,
            );
        }

        return $router;
    }

    private function address(Node $node): string
    {
        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            throw new ResourceOperationException(
                'task_vm.fleet_unavailable',
                "Node [{$node->name}] has no WireGuard address.",
                409,
            );
        }

        return $node->wireguard_ip;
    }

    private function rangeInUse(string $message): ResourceOperationException
    {
        return new ResourceOperationException('task_vm.range_in_use', $message, 409);
    }
}
