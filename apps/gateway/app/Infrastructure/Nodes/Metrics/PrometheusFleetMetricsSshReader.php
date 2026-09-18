<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Metrics;

use App\Domain\Nodes\Metrics\NodeFleetMetricsReader;
use App\Domain\Nodes\Metrics\NodeFleetMetricsSnapshot;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\NodeRole;
use JsonException;

/**
 * Reads one metrics snapshot per Node from the Metrics role's own Prometheus, over the same
 * Gateway-to-Node SSH execution path other `RemoteCommand` work uses.
 *
 * Prometheus binds `127.0.0.1:9090` on the Metrics Node only (see `MetricsRuntimeSpec`), so the
 * Gateway cannot reach it directly even pinned by WireGuard address; the one SSH round trip below
 * runs four `curl` calls against that loopback address and returns all four bodies in one
 * transcript. Each call is one instant PromQL query covering every scraped Node at once (no
 * per-Node filter), so both `NodeMetricsPrometheusReader` (one Node) and the fleet metrics
 * endpoint replay the same four responses instead of one SSH round trip per Node.
 */
final readonly class PrometheusFleetMetricsSshReader implements NodeFleetMetricsReader
{
    private const float COMMAND_TIMEOUT = 5.0;

    private const string PROMETHEUS_URL = 'http://127.0.0.1:9090/api/v1/query';

    /** @var non-empty-list<string> The four transcript sections, in the order the script prints them. */
    private const array SECTIONS = ['scalars', 'cores', 'pressure', 'disks'];

    /**
     * Every memory, swap, load, and boot-time metric a Linux or a Darwin `node_exporter` reports,
     * matched by name so the mapper can tell which family a Node actually sent.
     */
    private const string SCALAR_QUERY = '{__name__=~"node_memory_MemTotal_bytes|node_memory_MemAvailable_bytes'
        .'|node_memory_SwapTotal_bytes|node_memory_SwapFree_bytes|node_memory_total_bytes|node_memory_free_bytes'
        .'|node_memory_active_bytes|node_memory_inactive_bytes|node_memory_wired_bytes|node_memory_compressed_bytes'
        .'|node_memory_purgeable_bytes|node_memory_internal_bytes|node_memory_swap_total_bytes'
        .'|node_memory_swap_used_bytes|node_load1|node_load5|node_load15|node_boot_time_seconds"}';

    private const string CORES_QUERY = '1 - rate(node_cpu_seconds_total{mode="idle"}[1m])';

    private const string PRESSURE_QUERY = 'rate({__name__=~"node_pressure_(cpu|memory|io)_waiting_seconds_total"}[1m]) * 100';

    private const string DISKS_QUERY = '{__name__=~"node_filesystem_size_bytes|node_filesystem_avail_bytes"}';

    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
    ) {}

    public function read(): NodeFleetMetricsSnapshot
    {
        $metricsNode = $this->metricsNode();

        $result = $this->ssh->execute(
            new SshConnection(
                host: (string) $metricsNode->wireguard_ip,
                user: $metricsNode->user,
                port: 22,
                identityFile: $this->keys->privateKeyPath(),
                knownHostsFile: $this->knownHosts->path(),
                commandTimeout: self::COMMAND_TIMEOUT,
            ),
            new RemoteCommand(['bash', '-seu'], $this->script(), timeout: self::COMMAND_TIMEOUT),
        );

        if (! $result->succeeded() || $result->truncated) {
            $this->fail();
        }

        $sections = $this->parse($result->stdout);

        return new NodeFleetMetricsSnapshot(
            metricsNode: $metricsNode,
            snapshots: PrometheusNodeMetricsMapper::map(
                $sections['scalars'],
                $sections['cores'],
                $sections['pressure'],
                $sections['disks'],
                time(),
            ),
        );
    }

    private function metricsNode(): Node
    {
        $assignments = NodeRole::query()->where('role', RoleName::Metrics->value)->with('node')->get();

        if ($assignments->count() > 1) {
            throw new RoleAssignmentException('Metrics role assignment drift detected.');
        }

        $assignment = $assignments->first();

        if (! $assignment instanceof NodeRole) {
            throw new ResourceOperationException('metrics.assignment_missing', 'Metrics is not assigned.', 409);
        }

        $metricsNode = $assignment->node;

        if (
            $metricsNode->status !== LifecycleStatus::Active
            || ! is_string($metricsNode->wireguard_ip)
            || $metricsNode->wireguard_ip === ''
        ) {
            throw new ResourceOperationException('metrics.node_inactive', 'The Metrics node is not active.', 409);
        }

        return $metricsNode;
    }

    private function script(): string
    {
        $lines = ["url='".self::PROMETHEUS_URL."'"];

        foreach ([
            'scalars' => self::SCALAR_QUERY,
            'cores' => self::CORES_QUERY,
            'pressure' => self::PRESSURE_QUERY,
            'disks' => self::DISKS_QUERY,
        ] as $section => $query) {
            $escaped = str_replace("'", "'\\''", $query);
            $lines[] = "printf '===orbit:{$section}===\\n'";
            $lines[] = "curl -sS --max-time 3 --data-urlencode 'query={$escaped}' \"\$url\" || true";
            $lines[] = "printf '\\n'";
        }

        return implode("\n", $lines)."\n";
    }

    /** @return array{scalars: array<string, mixed>, cores: array<string, mixed>, pressure: array<string, mixed>, disks: array<string, mixed>} */
    private function parse(string $stdout): array
    {
        $pattern = '/===orbit:('.implode('|', self::SECTIONS).')===\n/';
        $parts = preg_split($pattern, $stdout, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false || count($parts) !== 1 + count(self::SECTIONS) * 2 || $parts[0] !== '') {
            $this->fail();
        }

        $decoded = [];

        for ($i = 1; $i < count($parts); $i += 2) {
            $section = $parts[$i];
            $body = trim($parts[$i + 1]);

            try {
                $value = $body === '' ? [] : json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $this->fail();
            }

            if (! is_array($value)) {
                $this->fail();
            }

            $decoded[$section] = $value;
        }

        foreach (self::SECTIONS as $section) {
            if (! array_key_exists($section, $decoded)) {
                $this->fail();
            }
        }

        /** @var array{scalars: array<string, mixed>, cores: array<string, mixed>, pressure: array<string, mixed>, disks: array<string, mixed>} */
        return $decoded;
    }

    private function fail(): never
    {
        throw new ResourceOperationException(
            errorCode: 'node.metrics_unreachable',
            message: 'Metrics could not be read from the Metrics node.',
            status: 502,
        );
    }
}
