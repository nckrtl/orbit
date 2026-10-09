<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\WireGuard\Ipv4Subnet;
use App\Domain\WireGuard\VpnSettings;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * The validated `task_vms` config. Resolve it from the container: the singleton validates once.
 *
 * Every value is validated whenever it is set, also while task VMs are off, so an operator can
 * prepare hosts and the hub before enabling claims. An unset value stays absent: no hosts, a null
 * Cluster, a null origin. `enabled` gates behaviour only, and requires the Cluster, the origin and
 * at least one host.
 *
 * The reserved WireGuard range is always a private network. As soon as task VMs are enabled or the
 * Cluster, the origin or a host is set, it must also lie inside the Gateway's VPN subnet, and no
 * bridge may overlap that subnet. With nothing set, the Gateway reads no VPN setting, so a fleet on
 * another subnet keeps working: a range outside the subnet reserves no fleet address.
 */
final readonly class TaskVmSettings
{
    private const array HostDefaults = [
        'project' => 'orbit-tasks',
        'network' => 'orbittask0',
        'image' => 'ubuntu-26.04-vm',
        'cpus' => 2,
        'memory' => '4GiB',
        'disk' => '20GiB',
        'pool' => 'default',
    ];

    private const array HostKeys = ['node_id', 'project', 'network', 'cidr', 'image', 'max_vms', 'cpus', 'memory', 'disk', 'pool'];

    private const array PrivateRanges = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];

    /**
     * @param  list<TaskVmHost>  $hosts
     * @param  list<string>  $piModels
     */
    public function __construct(
        public bool $enabled,
        public ?int $devClusterId,
        public string $wireguardRange,
        public array $hosts,
        public ?string $modelProxyOrigin,
        public ?string $piArtifactPath,
        public ?string $piArtifactSha256,
        public array $piModels,
    ) {}

    public static function fromConfig(): self
    {
        $enabled = Config::get('task_vms.enabled') === true;
        $range = self::wireguardRange(Config::get('task_vms.wireguard_range'));
        $clusterId = self::clusterId(Config::get('task_vms.dev_cluster_id'));
        $origin = self::origin(Config::get('task_vms.model_proxy_origin'));
        $hostEntries = Config::get('task_vms.incus.hosts');

        $configured = $enabled || $clusterId !== null || $origin !== null || $hostEntries !== [];
        $hosts = $configured ? self::hosts($hostEntries, self::vpnSubnet($range)) : [];

        if ($enabled) {
            match (true) {
                $clusterId === null => throw self::invalid('dev_cluster_id is required while task VMs are enabled.'),
                $origin === null => throw self::invalid('model_proxy_origin is required while task VMs are enabled.'),
                $hosts === [] => throw self::invalid('incus.hosts needs at least one host while task VMs are enabled.'),
                default => null,
            };
        }

        [$artifactPath, $artifactSha256] = self::artifact(
            self::filled(Config::get('task_vms.pi.artifact_path')),
            self::filled(Config::get('task_vms.pi.artifact_sha256')),
        );

        return new self(
            enabled: $enabled,
            devClusterId: $clusterId,
            wireguardRange: $range->value(),
            hosts: $hosts,
            modelProxyOrigin: $origin,
            piArtifactPath: $artifactPath,
            piArtifactSha256: $artifactSha256,
            piModels: self::models(Config::get('task_vms.pi.models')),
        );
    }

    public function host(int $nodeId): TaskVmHost
    {
        foreach ($this->hosts as $host) {
            if ($host->nodeId === $nodeId) {
                return $host;
            }
        }

        throw new TaskVmException('task_vm.unknown_host', "Node [{$nodeId}] is not a task VM host.");
    }

    private static function wireguardRange(mixed $value): Ipv4Subnet
    {
        try {
            $range = Ipv4Subnet::from(is_string($value) ? $value : '');
        } catch (InvalidArgumentException) {
            throw self::invalid('wireguard_range must be an IPv4 network, for example 10.44.0.128/25.');
        }
        if (! self::isPrivate($range)) {
            throw self::invalid('wireguard_range must be a private IPv4 network.');
        }

        return $range;
    }

    private static function vpnSubnet(Ipv4Subnet $range): Ipv4Subnet
    {
        $value = resolve(VpnSettings::class)->subnet();
        try {
            $subnet = Ipv4Subnet::from($value);
        } catch (InvalidArgumentException) {
            throw self::invalid("the VPN subnet [{$value}] is not an IPv4 network.");
        }
        if ($range->prefixLength() <= $subnet->prefixLength() || ! $subnet->contains($range->networkAddress())) {
            throw self::invalid("wireguard_range must be a smaller network inside the VPN subnet [{$subnet->value()}].");
        }

        return $subnet;
    }

    private static function clusterId(mixed $value): ?int
    {
        $value = self::filled($value);
        if ($value === null) {
            return null;
        }
        $id = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if (! is_int($id)) {
            throw self::invalid('dev_cluster_id must be a Cluster id.');
        }

        return $id;
    }

    /** @return array{?string, ?string} */
    private static function artifact(mixed $path, mixed $sha256): array
    {
        if (($path === null) !== ($sha256 === null)) {
            throw self::invalid('pi.artifact_path and pi.artifact_sha256 must be set together.');
        }
        if ($path !== null && (! is_string($path) || ! str_starts_with($path, '/') || str_contains($path, "\0"))) {
            throw self::invalid('pi.artifact_path must be an absolute path.');
        }
        if ($sha256 !== null && (! is_string($sha256) || preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1)) {
            throw self::invalid('pi.artifact_sha256 must be a lowercase SHA-256 digest.');
        }

        return [$path, $sha256];
    }

    /** @return list<TaskVmHost> */
    private static function hosts(mixed $value, Ipv4Subnet $vpnSubnet): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw self::invalid('incus.hosts must be a JSON list.');
        }
        $hosts = [];
        foreach ($value as $index => $entry) {
            if (! is_array($entry) || array_diff(array_keys($entry), self::HostKeys) !== []) {
                throw self::invalid("incus.hosts.{$index} has unknown keys.");
            }
            $entry = [...self::HostDefaults, ...$entry];
            $host = new TaskVmHost(
                nodeId: self::positiveInt($entry['node_id'] ?? null, 1_000_000_000, "incus.hosts.{$index}.node_id"),
                project: self::name($entry['project'], '/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', "incus.hosts.{$index}.project"),
                network: self::name($entry['network'], '/\Aorbittask[a-z0-9]{1,6}\z/D', "incus.hosts.{$index}.network"),
                cidr: self::bridge($entry['cidr'] ?? null, $vpnSubnet, "incus.hosts.{$index}.cidr"),
                image: self::name($entry['image'], '/\A[a-z0-9][a-z0-9.-]{0,62}\z/D', "incus.hosts.{$index}.image"),
                maxVms: self::positiveInt($entry['max_vms'] ?? null, 64, "incus.hosts.{$index}.max_vms"),
                cpus: self::positiveInt($entry['cpus'], 64, "incus.hosts.{$index}.cpus"),
                memory: self::name($entry['memory'], '/\A[1-9][0-9]{0,5}(?:MiB|GiB)\z/D', "incus.hosts.{$index}.memory"),
                disk: self::name($entry['disk'], '/\A[1-9][0-9]{0,5}(?:MiB|GiB)\z/D', "incus.hosts.{$index}.disk"),
                pool: self::name($entry['pool'], '/\A[a-z0-9](?:[a-z0-9_-]{0,61}[a-z0-9])?\z/D', "incus.hosts.{$index}.pool"),
            );
            if (isset($hosts[$host->nodeId])) {
                throw self::invalid("incus.hosts.{$index}.node_id is listed twice.");
            }
            $hosts[$host->nodeId] = $host;
        }

        return array_values($hosts);
    }

    private static function bridge(mixed $value, Ipv4Subnet $vpnSubnet, string $key): string
    {
        try {
            $subnet = Ipv4Subnet::from(is_string($value) ? $value : '');
        } catch (InvalidArgumentException) {
            throw self::invalid("{$key} must be an IPv4 network, for example 10.251.77.0/24.");
        }
        if ($subnet->prefixLength() < 16 || $subnet->prefixLength() > 28 || ! self::isPrivate($subnet)) {
            throw self::invalid("{$key} must be a private IPv4 network from /16 to /28.");
        }
        if ($subnet->contains($vpnSubnet->networkAddress()) || $vpnSubnet->contains($subnet->networkAddress())) {
            throw self::invalid("{$key} must not overlap the VPN subnet [{$vpnSubnet->value()}].");
        }

        return $subnet->value();
    }

    private static function origin(mixed $value): ?string
    {
        $value = self::filled($value);
        if ($value === null) {
            return null;
        }
        $parts = is_string($value) ? parse_url($value) : false;
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true) || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw self::invalid('model_proxy_origin must be an http(s) origin, for example http://10.44.0.3:8317.');
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /** @return list<string> */
    private static function models(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw self::invalid('pi.models must be a JSON list of model names.');
        }
        foreach ($value as $model) {
            if (! is_string($model) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/-]{0,127}\z/D', $model) !== 1) {
                throw self::invalid('pi.models must contain only model names.');
            }
        }

        return array_values(array_unique($value));
    }

    private static function positiveInt(mixed $value, int $maximum, string $key): int
    {
        if (! is_int($value) || $value < 1 || $value > $maximum) {
            throw self::invalid("{$key} must be an integer from 1 to {$maximum}.");
        }

        return $value;
    }

    private static function name(mixed $value, string $pattern, string $key): string
    {
        if (! is_string($value) || preg_match($pattern, $value) !== 1) {
            throw self::invalid("{$key} is invalid.");
        }

        return $value;
    }

    /** An unset or empty value is absent. */
    private static function filled(mixed $value): mixed
    {
        return $value === '' ? null : $value;
    }

    private static function isPrivate(Ipv4Subnet $subnet): bool
    {
        [$first, $last] = [$subnet->networkAddress(), $subnet->usableRange()[1]];
        foreach (self::PrivateRanges as $range) {
            $private = Ipv4Subnet::from($range);
            if ($private->contains($first) && $private->contains($last)) {
                return true;
            }
        }

        return false;
    }

    private static function invalid(string $reason): TaskVmException
    {
        return new TaskVmException('task_vm.invalid_config', "The task VM config is invalid: {$reason}", 500);
    }
}
