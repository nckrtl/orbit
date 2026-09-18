<?php

declare(strict_types=1);

namespace App\Support\Tui\Sources;

use App\Support\Metrics\PrometheusMetricsQueries;
use App\Support\Metrics\PrometheusNodeMetricsMapper;
use App\Support\Tui\Sources\Concerns\LimitsBackgroundRequestTime;
use Closure;
use Illuminate\Support\Facades\Http;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Requests\Metrics\ShowMetricsCredentialsRequest;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Responses\Metrics\MetricsCredentialsResponse;
use Orbit\Sdk\Responses\Nodes\NodesResponse;
use Throwable;

/**
 * The compact CPU, memory, swap, and disk snapshot `orbit top` draws per node, read directly from
 * the Metrics role's Grafana rather than through the Gateway API: Grafana's datasource proxy
 * re-serves its Prometheus datasource's HTTP API, authorized by the Grafana credential the
 * Gateway already stores (`metrics:credentials`), so a future metrics panel is a CLI-only change
 * and the Gateway never stores or forwards a metrics sample for this purpose (see the ADR).
 *
 * Everything is fetched lazily and cached for the life of this instance (one `orbit top` run):
 * the credential and the Prometheus datasource uid once, and the Node list once to resolve a
 * Node id to its WireGuard address (Prometheus's `instance` label is `address:9100`; see
 * `PrometheusNodeMetricsMapper`). A Node the fetch cannot place metrics for — Metrics disabled,
 * the credential rejected, the Node has no WireGuard address, or Prometheus has no samples for
 * its exporter — answers null (or is absent from the fleet map); `orbit top` renders that as the
 * dim "No metrics." rather than failing the screen.
 */
final class GrafanaPrometheusMetricsSource implements FleetNodeMetricsSource, NodeMetricsSource
{
    use LimitsBackgroundRequestTime;

    private const float REQUEST_TIMEOUT_SECONDS = 3.0;

    private const int BYTES_PER_GIB = 1024 ** 3;

    private ?MetricsCredentialsResponse $credentials = null;

    private bool $credentialsUnavailable = false;

    private ?string $datasourceUid = null;

    /** @var array<int, string>|null Node id to WireGuard address. */
    private ?array $addresses = null;

    /** @param  Closure(object, string): object  $send  Same shape as GatewayCommand::sendOrThrow(). */
    public function __construct(private readonly Closure $send) {}

    #[\Override]
    public function forNode(int $nodeId): ?array
    {
        $address = $this->addresses()[$nodeId] ?? null;

        if ($address === null) {
            return null;
        }

        $raw = $this->fetchSnapshots("{$address}:9100")[$address] ?? null;

        return $raw === null ? null : self::compact($raw);
    }

    #[\Override]
    public function forFleet(): array
    {
        $snapshots = $this->fetchSnapshots(null);

        if ($snapshots === []) {
            return [];
        }

        $result = [];

        foreach ($this->addresses() as $nodeId => $address) {
            if (isset($snapshots[$address])) {
                $result[$nodeId] = self::compact($snapshots[$address]);
            }
        }

        return $result;
    }

    /** @return array<string, array<string, mixed>> Raw snapshots keyed by instance address. */
    private function fetchSnapshots(?string $instance): array
    {
        $credentials = $this->credentials();

        if ($credentials === null) {
            return [];
        }

        $uid = $this->datasourceUid($credentials);

        if ($uid === null) {
            return [];
        }

        try {
            $scalars = $this->query($credentials, $uid, PrometheusMetricsQueries::scalars($instance));
            $cores = $this->query($credentials, $uid, PrometheusMetricsQueries::cores($instance));
            $pressure = $this->query($credentials, $uid, PrometheusMetricsQueries::pressure($instance));
            $disks = $this->query($credentials, $uid, PrometheusMetricsQueries::disks($instance));
        } catch (Throwable) {
            return [];
        }

        return PrometheusNodeMetricsMapper::map($scalars, $cores, $pressure, $disks, time());
    }

    private function credentials(): ?MetricsCredentialsResponse
    {
        if ($this->credentialsUnavailable) {
            return null;
        }

        if ($this->credentials !== null) {
            return $this->credentials;
        }

        try {
            $response = ($this->send)(self::withBackgroundTimeout(new ShowMetricsCredentialsRequest), MetricsCredentialsResponse::class);
        } catch (GatewayApiException) {
            $this->credentialsUnavailable = true;

            return null;
        }

        assert($response instanceof MetricsCredentialsResponse);

        return $this->credentials = $response;
    }

    private function datasourceUid(MetricsCredentialsResponse $credentials): ?string
    {
        if ($this->datasourceUid !== null) {
            return $this->datasourceUid;
        }

        try {
            $datasources = Http::withBasicAuth($credentials->username, $credentials->password)
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->get("{$credentials->url}/api/datasources")
                ->throw()
                ->json();
        } catch (Throwable) {
            return null;
        }

        foreach ((array) $datasources as $datasource) {
            if (is_array($datasource) && ($datasource['type'] ?? null) === 'prometheus' && is_string($datasource['uid'] ?? null)) {
                return $this->datasourceUid = $datasource['uid'];
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function query(MetricsCredentialsResponse $credentials, string $uid, string $promql): array
    {
        $decoded = Http::withBasicAuth($credentials->username, $credentials->password)
            ->timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->get("{$credentials->url}/api/datasources/proxy/uid/{$uid}/api/v1/query", ['query' => $promql])
            ->throw()
            ->json();

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<int, string> Node id to WireGuard address. */
    private function addresses(): array
    {
        if ($this->addresses !== null) {
            return $this->addresses;
        }

        try {
            $response = ($this->send)(self::withBackgroundTimeout(new ListNodesRequest), NodesResponse::class);
        } catch (GatewayApiException) {
            return $this->addresses = [];
        }

        assert($response instanceof NodesResponse);

        $addresses = [];

        foreach ($response->nodes as $node) {
            if (is_string($node->wireguardIp) && $node->wireguardIp !== '') {
                $addresses[$node->id] = $node->wireguardIp;
            }
        }

        return $this->addresses = $addresses;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{cores: list<float>, mem: array{float, float}, swap: array{float, float}, uptime: string, disks: list<array{string, float, float}>}
     */
    private static function compact(array $raw): array
    {
        return [
            'cores' => $raw['cores'],
            'mem' => [self::gib($raw['memory']['used']), self::gib($raw['memory']['total'])],
            'swap' => [self::gib($raw['swap']['used']), self::gib($raw['swap']['total'])],
            'uptime' => self::uptime($raw['uptime_seconds']),
            'disks' => array_map(
                static fn (array $disk): array => [$disk['mount'], self::gib($disk['used']), self::gib($disk['total'])],
                $raw['disks'],
            ),
        ];
    }

    private static function gib(int $bytes): float
    {
        return $bytes / self::BYTES_PER_GIB;
    }

    private static function uptime(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return match (true) {
            $days > 0 => "{$days}d {$hours}h {$minutes}m",
            $hours > 0 => "{$hours}h {$minutes}m",
            default => "{$minutes}m",
        };
    }
}
