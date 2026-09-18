<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Metrics;

use App\Domain\Metrics\MetricsCredentialManager;
use App\Domain\Nodes\Metrics\NodeMetricsReader;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\PrometheusMetricsQueries;
use App\Infrastructure\Metrics\PrometheusNodeMetricsMapper;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads one Node's metrics snapshot through the Metrics role's own Grafana, the same gate every
 * other Metrics read goes through: Grafana's datasource proxy re-serves Prometheus's HTTP API
 * under `/api/datasources/proxy/uid/{uid}/api/v1/query`, authorized by the stored Grafana
 * credential (`MetricsCredentialManager`), so this never talks to Prometheus directly and never
 * opens a new port or route.
 *
 * The Gateway already has a standing firewall path to Grafana on the Metrics Node's WireGuard
 * address, port 3000 (see `MetricsPublicationSshExecutor::converge()`; that is the same address
 * and port `metrics.orbit`'s Caddy site reverse-proxies to), so this calls it directly instead of
 * going out through `metrics.orbit`: the Gateway host cannot resolve `*.orbit` names, and looping
 * back through its own published hostname would add a hop for no benefit.
 */
final readonly class GrafanaPrometheusNodeMetricsReader implements NodeMetricsReader
{
    private const float TIMEOUT = 5.0;

    public function __construct(private MetricsCredentialManager $credentials) {}

    public function read(Node $node): array
    {
        $address = $node->wireguard_ip;

        if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $this->fail($node);
        }

        $metricsAddress = $this->metricsNodeAddress();
        $credentials = $this->credentials->credentials();
        $base = "http://{$metricsAddress}:3000";
        $instance = "{$address}:9100";

        try {
            $uid = $this->prometheusDatasourceUid($base, $credentials->username, $credentials->password);
            $scalars = $this->query($base, $credentials->username, $credentials->password, $uid, PrometheusMetricsQueries::scalars($instance));
            // Only the scalars decide whether the Node has metrics. The rest enrich the snapshot,
            // so one query this Prometheus refuses must not fail the whole read.
            $cores = $this->optional($base, $credentials->username, $credentials->password, $uid, PrometheusMetricsQueries::cores($instance));
            $pressure = $this->optional($base, $credentials->username, $credentials->password, $uid, PrometheusMetricsQueries::pressure($instance));
            $disks = $this->optional($base, $credentials->username, $credentials->password, $uid, PrometheusMetricsQueries::disks($instance));
        } catch (Throwable) {
            $this->fail($node);
        }

        $snapshot = PrometheusNodeMetricsMapper::map($scalars, $cores, $pressure, $disks, time())[$address] ?? null;

        if ($snapshot === null) {
            $this->fail($node);
        }

        return $snapshot;
    }

    private function metricsNodeAddress(): string
    {
        $assignment = NodeRole::query()->where('role', RoleName::Metrics->value)->with('node')->first();

        if (
            ! $assignment instanceof NodeRole
            || ! is_string($assignment->node->wireguard_ip)
            || $assignment->node->wireguard_ip === ''
        ) {
            throw new ResourceOperationException('metrics.assignment_missing', 'Metrics is not assigned.', 409);
        }

        return $assignment->node->wireguard_ip;
    }

    private function prometheusDatasourceUid(string $base, string $username, #[\SensitiveParameter] string $password): string
    {
        $datasources = Http::baseUrl($base)
            ->withBasicAuth($username, $password)
            ->timeout(self::TIMEOUT)
            ->get('/api/datasources')
            ->throw()
            ->json();

        foreach ((array) $datasources as $datasource) {
            if (is_array($datasource) && ($datasource['type'] ?? null) === 'prometheus' && is_string($datasource['uid'] ?? null)) {
                return $datasource['uid'];
            }
        }

        throw new \RuntimeException('Grafana has no Prometheus datasource configured.');
    }

    /** @return array<string, mixed> An enriching query's result, or nothing when Prometheus refuses it. */
    private function optional(string $base, string $username, #[\SensitiveParameter] string $password, string $uid, string $promql): array
    {
        try {
            return $this->query($base, $username, $password, $uid, $promql);
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string, mixed> */
    private function query(string $base, string $username, #[\SensitiveParameter] string $password, string $uid, string $promql): array
    {
        $decoded = Http::baseUrl($base)
            ->withBasicAuth($username, $password)
            ->timeout(self::TIMEOUT)
            ->get("/api/datasources/proxy/uid/{$uid}/api/v1/query", ['query' => $promql])
            ->throw()
            ->json();

        return is_array($decoded) ? $decoded : [];
    }

    private function fail(Node $node): never
    {
        throw new ResourceOperationException(
            errorCode: 'node.metrics_unreachable',
            message: "Node [{$node->name}] metrics could not be read.",
            status: 502,
        );
    }
}
