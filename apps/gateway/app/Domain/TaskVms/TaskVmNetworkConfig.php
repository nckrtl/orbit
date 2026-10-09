<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\Ipv4Subnet;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * Temporary reader for the `task_vms` values that the network setup needs.
 *
 * Replace it with `TaskVmSettings` once that class is on main. The keys and defaults match it.
 */
final readonly class TaskVmNetworkConfig
{
    private const string DefaultRange = '10.44.64.0/20';

    private const array HostDefaults = [
        'project' => 'orbit-tasks',
        'network' => 'orbittask0',
        'image' => 'ubuntu-26.04-vm',
        'pool' => 'default',
    ];

    public function wireguardRange(): Ipv4Subnet
    {
        $value = Config::get('task_vms.wireguard_range') ?? self::DefaultRange;

        try {
            return Ipv4Subnet::from(is_string($value) ? $value : '');
        } catch (InvalidArgumentException) {
            throw self::invalid('task_vms.wireguard_range must be an IPv4 network.');
        }
    }

    public function devClusterId(): int
    {
        $value = Config::get('task_vms.dev_cluster_id');

        if (! is_int($value) || $value < 1) {
            throw self::invalid('task_vms.dev_cluster_id must be a Cluster id.');
        }

        return $value;
    }

    /** @return array{host: string, port: int} */
    public function modelProxyEndpoint(): array
    {
        $value = Config::get('task_vms.model_proxy_origin');
        $parts = is_string($value) ? parse_url($value) : false;
        $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;

        if (! is_array($parts) || ! in_array($scheme, ['http', 'https'], true) || ! isset($parts['host'])) {
            throw self::invalid('task_vms.model_proxy_origin must be an http(s) origin.');
        }

        return ['host' => $parts['host'], 'port' => $parts['port'] ?? ($scheme === 'https' ? 443 : 80)];
    }

    /** @return array{project: string, network: string, cidr: string, pool: string, image: string} */
    public function host(int $nodeId): array
    {
        $hosts = Config::get('task_vms.incus.hosts');

        foreach (is_array($hosts) ? $hosts : [] as $entry) {
            if (! is_array($entry) || ($entry['node_id'] ?? null) !== $nodeId) {
                continue;
            }

            $entry = [...self::HostDefaults, ...$entry];
            $values = [];

            foreach (['project', 'network', 'cidr', 'pool', 'image'] as $key) {
                if (! is_string($entry[$key] ?? null)) {
                    throw self::invalid("task_vms.incus.hosts entry for Node [{$nodeId}] has no valid [{$key}].");
                }

                $values[$key] = $entry[$key];
            }

            return [
                'project' => $values['project'],
                'network' => $values['network'],
                'cidr' => $values['cidr'],
                'pool' => $values['pool'],
                'image' => $values['image'],
            ];
        }

        throw new ResourceOperationException('task_vm.unknown_host', "Node [{$nodeId}] is not a task VM host.", 409);
    }

    private static function invalid(string $message): ResourceOperationException
    {
        return new ResourceOperationException('task_vm.settings_invalid', $message);
    }
}
