<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\WireGuard\Ipv4Subnet;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * The validated `task_vms` config. Resolve it from the container: the singleton validates once.
 *
 * The reserved WireGuard range is always validated, because address allocation skips it even
 * while the feature is off. The rest is validated only when `enabled` is true.
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
        public int $devClusterId,
        public string $wireguardRange,
        public array $hosts,
        public string $modelProxyOrigin,
        public ?string $piArtifactPath,
        public ?string $piArtifactSha256,
        public array $piModels,
    ) {}

    public static function fromConfig(): self
    {
        $range = self::wireguardRange(Config::get('task_vms.wireguard_range'));
        if (Config::get('task_vms.enabled') !== true) {
            return new self(false, 0, $range->value(), [], '', null, null, []);
        }

        $clusterId = Config::get('task_vms.dev_cluster_id');
        if (! is_int($clusterId) || $clusterId < 1) {
            throw self::invalid('dev_cluster_id must be a Cluster id.');
        }
        $artifactPath = Config::get('task_vms.pi.artifact_path');
        $artifactSha256 = Config::get('task_vms.pi.artifact_sha256');
        if (($artifactPath === null) !== ($artifactSha256 === null)) {
            throw self::invalid('pi.artifact_path and pi.artifact_sha256 must be set together.');
        }
        if ($artifactPath !== null && (! is_string($artifactPath) || ! str_starts_with($artifactPath, '/') || str_contains($artifactPath, "\0"))) {
            throw self::invalid('pi.artifact_path must be an absolute path.');
        }
        if ($artifactSha256 !== null && (! is_string($artifactSha256) || preg_match('/\A[a-f0-9]{64}\z/D', $artifactSha256) !== 1)) {
            throw self::invalid('pi.artifact_sha256 must be a lowercase SHA-256 digest.');
        }

        return new self(
            enabled: true,
            devClusterId: $clusterId,
            wireguardRange: $range->value(),
            hosts: self::hosts(Config::get('task_vms.incus.hosts'), $range),
            modelProxyOrigin: self::origin(Config::get('task_vms.model_proxy_origin')),
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
            throw self::invalid('wireguard_range must be an IPv4 network, for example 10.44.64.0/20.');
        }
        if (! self::isPrivate($range)) {
            throw self::invalid('wireguard_range must be a private IPv4 network.');
        }

        return $range;
    }

    /** @return list<TaskVmHost> */
    private static function hosts(mixed $value, Ipv4Subnet $wireguardRange): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            throw self::invalid('incus.hosts must be a non-empty JSON list.');
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
                cidr: self::bridge($entry['cidr'] ?? null, $wireguardRange, "incus.hosts.{$index}.cidr"),
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

    private static function bridge(mixed $value, Ipv4Subnet $wireguardRange, string $key): string
    {
        try {
            $subnet = Ipv4Subnet::from(is_string($value) ? $value : '');
        } catch (InvalidArgumentException) {
            throw self::invalid("{$key} must be an IPv4 network, for example 10.251.77.0/24.");
        }
        if ($subnet->prefixLength() < 16 || $subnet->prefixLength() > 28 || ! self::isPrivate($subnet)) {
            throw self::invalid("{$key} must be a private IPv4 network from /16 to /28.");
        }
        if ($subnet->contains($wireguardRange->networkAddress()) || $wireguardRange->contains($subnet->networkAddress())) {
            throw self::invalid("{$key} must not overlap wireguard_range.");
        }

        return $subnet->value();
    }

    private static function origin(mixed $value): string
    {
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
