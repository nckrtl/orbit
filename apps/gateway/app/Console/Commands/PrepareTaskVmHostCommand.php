<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmNetworkConfig;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\TaskVms\TaskVmSetupScript;
use App\Models\Node;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class PrepareTaskVmHostCommand extends Command
{
    private const array Patterns = [
        'project' => '/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D',
        'network' => '/\Aorbittask[a-z0-9]{1,6}\z/D',
        'pool' => '/\A[a-z0-9](?:[a-z0-9_-]{0,61}[a-z0-9])?\z/D',
        'image' => '/\A[a-z0-9][a-z0-9.-]{0,62}\z/D',
    ];

    private const array PrivateRanges = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];

    #[\Override]
    protected $signature = 'task-vms:prepare-host {node : Id or name of the Incus host Node}';

    #[\Override]
    protected $description = 'Prepare an Incus host for task VMs: project, image, bridge, egress ACL, profile and the ufw route rule.';

    public function handle(TaskVmNetworkConfig $config, VpnSettings $vpn, TaskVmSetupScript $script): int
    {
        try {
            $node = $this->node((string) $this->argument('node'));
            $host = $config->host($node->id);
            $this->validate($host, [$config->wireguardRange(), Ipv4Subnet::from($vpn->subnet())]);
            $script->run($node, TaskVmSetupScript::HostScript, [
                $host['project'],
                $host['network'],
                $host['cidr'],
                $host['pool'],
                $host['image'],
            ]);
        } catch (ResourceOperationException|InvalidArgumentException $exception) {
            $code = $exception instanceof ResourceOperationException ? $exception->errorCode : 'vpn.subnet_invalid';
            $this->error("[{$code}] {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Node [{$node->name}] is ready for task VMs on bridge [{$host['network']}].");

        return self::SUCCESS;
    }

    private function node(string $reference): Node
    {
        $node = ctype_digit($reference)
            ? Node::query()->find((int) $reference)
            : Node::query()->where('name', $reference)->first();

        if (! $node instanceof Node || $node->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException('task_vm.unknown_host', "Node [{$reference}] is not an active Node.", 404);
        }

        return $node;
    }

    /**
     * @param  array{project: string, network: string, cidr: string, pool: string, image: string}  $host
     * @param  list<Ipv4Subnet>  $fleet
     */
    private function validate(array $host, array $fleet): void
    {
        foreach (self::Patterns as $key => $pattern) {
            if (preg_match($pattern, $host[$key]) !== 1) {
                throw $this->invalid("The host {$key} [{$host[$key]}] is invalid.");
            }
        }

        try {
            $bridge = Ipv4Subnet::from($host['cidr']);
        } catch (InvalidArgumentException) {
            throw $this->invalid("The host cidr [{$host['cidr']}] is not an IPv4 network.");
        }

        $private = array_filter(
            self::PrivateRanges,
            static fn (string $range): bool => Ipv4Subnet::from($range)->contains($bridge->networkAddress())
                && $bridge->prefixLength() >= Ipv4Subnet::from($range)->prefixLength(),
        );

        if ($bridge->prefixLength() < 16 || $bridge->prefixLength() > 28 || $private === []) {
            throw $this->invalid("The host cidr [{$bridge->value()}] must be a private network from /16 to /28.");
        }

        foreach ($fleet as $subnet) {
            if ($subnet->contains($bridge->networkAddress()) || $bridge->contains($subnet->networkAddress())) {
                throw $this->invalid("The host cidr [{$bridge->value()}] overlaps [{$subnet->value()}].");
            }
        }
    }

    private function invalid(string $message): ResourceOperationException
    {
        return new ResourceOperationException('task_vm.settings_invalid', $message);
    }
}
