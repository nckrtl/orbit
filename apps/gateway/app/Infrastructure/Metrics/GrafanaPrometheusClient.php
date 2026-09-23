<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\Metrics\MetricsCredentialManager;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Models\NodeRole;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Runs instant PromQL queries against the Metrics role's Prometheus, through Grafana's datasource
 * proxy, which is the only path to it: Prometheus binds loopback on the Metrics Node, and Grafana
 * already re-serves its HTTP API authorized by the credential the Gateway stores
 * ([ADR 0088](/decisions/0088-cli-reads-display-metrics-from-grafana)).
 *
 * Grafana is reached on the Metrics Node's WireGuard address rather than through the published
 * `metrics.orbit` hostname, because the Gateway host does not resolve `*.orbit` names and already
 * has a standing firewall path to that address on port 3000.
 *
 * The credential and datasource uid are resolved once per instance, so a caller that runs several
 * queries in one request pays for that discovery only once.
 */
final class GrafanaPrometheusClient
{
    private const float TIMEOUT = 5.0;

    private ?string $base = null;

    private ?string $datasourceUid = null;

    public function __construct(private readonly MetricsCredentialManager $credentials) {}

    /**
     * The decoded `/api/v1/query` response for one instant query.
     *
     * @return array<string, mixed>
     */
    public function query(string $promql): array
    {
        $credentials = $this->credentials->storedCredentials();
        $base = $this->base ??= 'http://'.$this->metricsNodeAddress().':3000';
        $uid = $this->datasourceUid ??= $this->resolveDatasourceUid($base, $credentials->username, $credentials->password);

        $decoded = Http::baseUrl($base)
            ->withBasicAuth($credentials->username, $credentials->password)
            ->timeout(self::TIMEOUT)
            ->get("/api/datasources/proxy/uid/{$uid}/api/v1/query", ['query' => $promql])
            ->throw()
            ->json();

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Every `result` entry of an instant query, or nothing when the query failed or matched no
     * series. A caller that only enriches a response uses this so one refused query cannot fail
     * the whole request.
     *
     * @return list<array<string, mixed>>
     */
    public function series(string $promql): array
    {
        try {
            $decoded = $this->query($promql);
        } catch (\Throwable) {
            return [];
        }

        $result = $decoded['data']['result'] ?? null;

        return is_array($result) ? array_values(array_filter($result, is_array(...))) : [];
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

    private function resolveDatasourceUid(string $base, string $username, #[\SensitiveParameter] string $password): string
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

        throw new RuntimeException('Grafana has no Prometheus datasource configured.');
    }
}
