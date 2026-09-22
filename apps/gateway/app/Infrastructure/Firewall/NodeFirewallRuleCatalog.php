<?php

declare(strict_types=1);

namespace App\Infrastructure\Firewall;

use App\Domain\Analytics\AnalyticsRoleSettings;
use App\Domain\Analytics\AnalyticsRoleSettingsRepository;
use App\Domain\Analytics\AnalyticsStorageConnection;
use App\Domain\Clusters\ClusterState;
use App\Domain\Firewall\FirewallOperationException;
use App\Domain\Firewall\RouterLanIngressPolicy;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\PublicRouteEligibility;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppProd\AppProdSiteRepository;
use App\Infrastructure\Metrics\MetricsFootprint;
use App\Infrastructure\Metrics\ServiceMetricsConfigRenderer;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Process;

final readonly class NodeFirewallRuleCatalog
{
    public function __construct(
        private AppProdSiteRepository $appProdSites = new AppProdSiteRepository,
        private PublicRouteEligibility $publicRoutes = new PublicRouteEligibility,
    ) {}

    /** @return list<UfwManagedRule> */
    public function forNode(Node $node): array
    {
        return [
            $this->rule('orbit:public-ssh-recovery', (string) $node->public_ssh_port),
            $this->wireguardMemberTrust($node),
        ];
    }

    /**
     * Baseline rules role converge keeps on the Node right now.
     *
     * `forNode()` still returns both constructors so bootstrap and public-SSH restore can apply
     * the recovery rule after roles exist. The first active role closes public SSH.
     *
     * @return list<UfwManagedRule>
     */
    public function desiredBaseline(Node $node): array
    {
        $wireguard = $this->wireguardMemberTrust($node);

        if ($this->activeRoleClosesPublicSsh($node)) {
            return [$wireguard];
        }

        return [
            $this->rule('orbit:public-ssh-recovery', (string) $node->public_ssh_port),
            $wireguard,
        ];
    }

    private function activeRoleClosesPublicSsh(Node $node): bool
    {
        if ($node->relationLoaded('roles')) {
            return $node->roles->contains(
                static fn (NodeRole $assignment): bool => $assignment->status === LifecycleStatus::Active,
            );
        }

        return $node->roles()->where('status', LifecycleStatus::Active)->exists();
    }

    /** @return list<UfwManagedRule> */
    public function forRole(Node $node, RoleName $role): array
    {
        return match ($role) {
            RoleName::Gateway => [
                $this->rule('orbit:gateway-https', '443', 'any', 'orbit'),
            ],
            RoleName::Vpn => [],
            RoleName::Router => $this->routerLanIngress($node),
            RoleName::Ingress => $this->ingressPublicHttp($node),
            RoleName::AppDev => [],
            RoleName::AppProd => $this->appProdSites->requiresPublicFirewall($node)
                ? [
                    $this->rule('orbit:app-prod-http', '80'),
                    $this->rule('orbit:app-prod-https', '443'),
                ]
                : [],
            RoleName::Metrics, RoleName::Database => [],
            RoleName::WebSocket => [],
            RoleName::Analytics => [
                $this->rule('orbit:analytics-https', '443', $this->wireguardIp($node), 'orbit'),
                ...$this->analyticsLocalStorage($node),
            ],
        };
    }

    /**
     * Plausible reaches a storage Process on another Node over WireGuard, which every managed Node
     * already admits. A storage Process on the role's own Node is different: the request leaves
     * the `plausible` container through the Docker bridge, so it needs a rule of its own.
     *
     * @return list<UfwManagedRule>
     */
    private function analyticsLocalStorage(Node $node): array
    {
        $settings = $node->exists ? app(AnalyticsRoleSettingsRepository::class)->find($node) : null;

        if (! $settings instanceof AnalyticsRoleSettings) {
            return [];
        }

        $rules = [];

        foreach ([
            ['orbit:analytics-postgres-local', $settings->postgresProcessId, 5432],
            ['orbit:analytics-clickhouse-local', $settings->clickhouseProcessId, 8123],
        ] as [$comment, $processId, $containerPort]) {
            $process = Process::query()->find($processId);

            if (! $process instanceof Process || $process->owner_type !== Node::class || $process->owner_id !== $node->id) {
                continue;
            }

            try {
                $port = AnalyticsStorageConnection::publishedPort($process, $containerPort);
            } catch (ResourceOperationException) {
                continue;
            }

            $rules[] = $this->rule($comment, (string) $port, $this->wireguardIp($node), 'docker0');
        }

        return $rules;
    }

    /** @return non-empty-list<UfwManagedRule> */
    public function retiredForRole(Node $node, RoleName $role): array
    {
        $rules = [$this->rule('orbit:vpn-ssh', '22', $this->wireguardIp($node), 'orbit')];

        if ($role === RoleName::AppProd) {
            if ($this->appProdSites->requiresPublicFirewall($node)) {
                return $rules;
            }

            return [
                ...$rules,
                $this->rule('orbit:app-prod-http', '80'),
                $this->rule('orbit:app-prod-https', '443'),
            ];
        }

        if ($role !== RoleName::AppDev) {
            return $rules;
        }

        return [
            ...$rules,
            $this->rule('orbit:app-dev-http', '80', $this->wireguardIp($node), 'orbit'),
            $this->rule('orbit:app-dev-https', '443', $this->wireguardIp($node), 'orbit'),
            $this->rule('orbit:app-dev-direct-http', '80'),
            $this->rule('orbit:app-dev-direct-https', '443'),
        ];
    }

    /** @return list<UfwManagedRule> */
    private function ingressPublicHttp(Node $node): array
    {
        $assignment = NodeRole::query()
            ->where('node_id', $node->id)
            ->where('role', RoleName::Ingress)
            ->first();

        if (! $assignment instanceof NodeRole || $assignment->cluster_id === null) {
            return [];
        }

        if (! $this->publicRoutes->clusterHasActivePublicRoute($assignment->cluster_id)) {
            return [];
        }

        return [
            $this->rule('orbit:ingress-http', '80'),
            $this->rule('orbit:ingress-https', '443'),
        ];
    }

    /**
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     * @param  array<int, array{state?: ClusterState}>  $clusterOverrides
     * @return list<UfwManagedRule>
     */
    public function routerLanIngress(
        Node $node,
        array $nodeOverrides = [],
        array $clusterOverrides = [],
    ): array {
        $policy = new RouterLanIngressPolicy;
        $router = $nodeOverrides === []
            ? $node
            : $this->withNodeOverrides($node, $nodeOverrides[$node->id] ?? []);
        $destination = is_string($router->lan_ip) ? $router->lan_ip : null;

        if ($destination === null || filter_var($destination, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return [];
        }

        return array_map(
            fn (Node $source): UfwManagedRule => $this->routerLanIngressRule(
                (string) $source->lan_ip,
                $destination,
                $source->id,
            ),
            $policy->eligibleSources($node, $nodeOverrides, $clusterOverrides),
        );
    }

    public function routerLanIngressRule(string $source, string $destination, int $sourceNodeId): UfwManagedRule
    {
        $comment = new RouterLanIngressPolicy()->commentFor($sourceNodeId);

        return new UfwManagedRule(
            new UfwRuleShape(
                comment: $comment,
                action: 'allow',
                direction: 'in',
                source: $source,
                destination: $destination,
                port: '443',
                protocol: 'tcp',
                inInterface: null,
                outInterface: null,
                family: 'v4',
            ),
            [
                'sudo',
                'ufw',
                'allow',
                'in',
                'proto',
                'tcp',
                'from',
                $source,
                'to',
                $destination,
                'port',
                '443',
                'comment',
                $comment,
            ],
        );
    }

    /**
     * @param  array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}  $override
     */
    private function withNodeOverrides(Node $node, array $override): Node
    {
        if ($override === []) {
            return $node;
        }

        $clone = clone $node;

        foreach (['cluster_id', 'lan_ip', 'status', 'wireguard_ip', 'wireguard_public_key'] as $attribute) {
            if (array_key_exists($attribute, $override)) {
                $clone->setAttribute($attribute, $override[$attribute]);
            }
        }

        return $clone;
    }

    private function wireguardMemberTrust(Node $node): UfwManagedRule
    {
        $destination = $this->wireguardIp($node);

        return new UfwManagedRule(
            new UfwRuleShape(
                comment: 'orbit:wireguard-members',
                action: 'allow',
                direction: 'in',
                source: 'any',
                destination: $destination,
                port: 'any',
                protocol: 'any',
                inInterface: 'orbit',
                outInterface: null,
                family: 'v4',
            ),
            [
                'sudo',
                'ufw',
                'allow',
                'in',
                'on',
                'orbit',
                'to',
                $destination,
                'comment',
                'orbit:wireguard-members',
            ],
        );
    }

    public function metricsExporter(Node $node, Node $metricsNode): UfwManagedRule
    {
        return $this->metricsRule(
            MetricsFootprint::ExporterFirewallComment,
            $this->metricsExporterAddress($metricsNode),
            $this->metricsExporterAddress($node),
            MetricsFootprint::ExporterPort,
        );
    }

    public function metricsCadvisor(Node $node, Node $metricsNode): UfwManagedRule
    {
        return $this->metricsRule(
            MetricsFootprint::CadvisorFirewallComment,
            $this->metricsExporterAddress($metricsNode),
            $this->metricsExporterAddress($node),
            MetricsFootprint::CadvisorPort,
        );
    }

    public function metricsService(Node $node, Node $metricsNode, string $kind, bool $allow): UfwManagedRule
    {
        $port = match ($kind) {
            'caddy' => ServiceMetricsConfigRenderer::CaddyPort,
            'fpm' => ServiceMetricsConfigRenderer::FpmPort,
            default => throw new \InvalidArgumentException('Unknown service metrics kind.'),
        };
        $action = $allow ? 'allow' : 'deny';
        $source = $allow ? $this->metricsExporterAddress($metricsNode) : 'any';
        $destination = $this->metricsExporterAddress($node);
        $comment = 'orbit:metrics-service-'.$kind.'-'.$action;

        return new UfwManagedRule(
            new UfwRuleShape($comment, $action, 'in', $source, $destination, $port, 'tcp', 'orbit', null, 'v4'),
            ['sudo', 'ufw', $action, 'in', 'on', 'orbit', 'proto', 'tcp', 'from', $source, 'to', $destination, 'port', $port, 'comment', $comment],
        );
    }

    public function metricsGrafanaUpstream(Node $metricsNode, string $gatewayAddress): UfwManagedRule
    {
        if (filter_var($gatewayAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new ResourceOperationException(
                'metrics.publication_address_invalid',
                'Metrics publication requires valid WireGuard IPv4 addresses.',
                409,
            );
        }

        return $this->metricsRule(
            MetricsFootprint::PublicationFirewallComment,
            $gatewayAddress,
            $this->metricsPublicationAddress($metricsNode),
            MetricsFootprint::PublicationPort,
        );
    }

    public function metricsGrafanaIsolation(Node $metricsNode): UfwManagedRule
    {
        $destination = $this->metricsPublicationAddress($metricsNode);

        return new UfwManagedRule(
            new UfwRuleShape(
                comment: MetricsFootprint::PublicationFirewallDenyComment,
                action: 'deny',
                direction: 'in',
                source: 'any',
                destination: $destination,
                port: MetricsFootprint::PublicationPort,
                protocol: 'tcp',
                inInterface: MetricsFootprint::WireGuardInterface,
                outInterface: null,
                family: 'v4',
            ),
            [
                'sudo',
                'ufw',
                'deny',
                'in',
                'on',
                MetricsFootprint::WireGuardInterface,
                'proto',
                'tcp',
                'from',
                'any',
                'to',
                $destination,
                'port',
                MetricsFootprint::PublicationPort,
                'comment',
                MetricsFootprint::PublicationFirewallDenyComment,
            ],
        );
    }

    private function rule(
        string $comment,
        string $port,
        ?string $destination = null,
        ?string $interface = null,
    ): UfwManagedRule {
        $destination ??= 'any';
        $on = $interface === null ? [] : ['on', $interface];

        return new UfwManagedRule(
            new UfwRuleShape(
                $comment,
                'allow',
                'in',
                'any',
                $destination,
                $port,
                'tcp',
                $interface,
                null,
                $destination === 'any' ? null : 'v4',
            ),
            [
                'sudo',
                'ufw',
                'allow',
                'in',
                ...$on,
                'proto',
                'tcp',
                'to',
                $destination,
                'port',
                $port,
                'comment',
                $comment,
            ],
        );
    }

    private function metricsRule(
        string $comment,
        string $source,
        string $destination,
        string $port,
    ): UfwManagedRule {
        return new UfwManagedRule(
            new UfwRuleShape(
                comment: $comment,
                action: 'allow',
                direction: 'in',
                source: $source,
                destination: $destination,
                port: $port,
                protocol: 'tcp',
                inInterface: MetricsFootprint::WireGuardInterface,
                outInterface: null,
                family: 'v4',
            ),
            [
                'sudo',
                'ufw',
                'allow',
                'in',
                'on',
                MetricsFootprint::WireGuardInterface,
                'proto',
                'tcp',
                'from',
                $source,
                'to',
                $destination,
                'port',
                $port,
                'comment',
                $comment,
            ],
        );
    }

    private function metricsExporterAddress(Node $node): string
    {
        $address = $node->wireguard_ip;

        if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new ResourceOperationException(
                'metrics.exporter_address_invalid',
                "Node [{$node->name}] has no valid WireGuard IPv4 address.",
                409,
            );
        }

        return $address;
    }

    private function metricsPublicationAddress(Node $node): string
    {
        $address = $node->wireguard_ip;

        if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new ResourceOperationException(
                'metrics.publication_address_invalid',
                'Metrics publication requires valid WireGuard IPv4 addresses.',
                409,
            );
        }

        return $address;
    }

    private function wireguardIp(Node $node): string
    {
        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            throw new FirewallOperationException(
                step: 'host-firewall',
                errorCode: 'node.firewall_convergence_failed',
                message: "Node [{$node->name}] has no WireGuard address for a scoped firewall rule.",
            );
        }

        return $node->wireguard_ip;
    }
}
