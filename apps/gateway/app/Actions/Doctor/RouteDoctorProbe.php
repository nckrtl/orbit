<?php

declare(strict_types=1);

namespace App\Actions\Doctor;

use App\Data\Doctor\DoctorFamilyReportData;
use App\Data\Doctor\DoctorIssueData;
use App\Domain\Doctor\CustomProxyRouteInspector;
use App\Domain\Doctor\CustomProxyRouteObservation;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorFamilyProbe;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\DoctorIssueKind;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\RouteDoctorIssueCode;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Route;
use App\Models\RouteCustomProxy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final readonly class RouteDoctorProbe implements DoctorFamilyProbe
{
    public function __construct(
        private CustomProxyRouteInspector $inspector,
    ) {}

    public function family(): DoctorFamily
    {
        return DoctorFamily::Route;
    }

    public function inspect(DoctorNodeContext $context): DoctorFamilyReportData
    {
        $routes = $this->servedRoutes($context->node);
        $issues = $this->lifecycleIssues($routes);
        $proxies = RouteCustomProxy::query()
            ->with('route')
            ->where('node_id', $context->node->id)
            ->orderBy('route_id')
            ->get();

        if ($proxies->isEmpty()) {
            return DoctorFamilyReportData::fromIssues(DoctorFamily::Route, $routes->count(), $issues);
        }

        if (! $context->inspection->reachable) {
            return DoctorFamilyReportData::fromIssues(DoctorFamily::Route, $routes->count(), [...$issues, new DoctorIssueData(
                RouteDoctorIssueCode::NodeUnreachable,
                DoctorIssueKind::Unverifiable,
                'route',
                null,
                null,
                'Custom proxy Route state cannot be inspected because the node is unreachable.',
                'reachable',
                'unreachable',
            )]);
        }

        foreach ($proxies as $proxy) {
            $route = $proxy->route;

            if (! $route instanceof Route) {
                $issues[] = $this->failure($proxy, null);

                continue;
            }

            try {
                $observation = $this->inspector->inspect($proxy);
            } catch (DoctorInspectionException) {
                $issues[] = $this->failure($proxy, $route->domain);

                continue;
            }

            $issues = [...$issues, ...$this->issuesFor($proxy, $route, $observation)];
        }

        return DoctorFamilyReportData::fromIssues(DoctorFamily::Route, $routes->count(), $issues);
    }

    /**
     * The Routes this Node serves: Node-scoped Routes on the Node, and Cluster-scoped Routes of every Cluster
     * whose Router role the Node holds.
     *
     * @return Collection<int, Route>
     */
    private function servedRoutes(Node $node): Collection
    {
        $clusters = NodeRole::query()
            ->where('node_id', $node->id)
            ->where('role', RoleName::Router)
            ->where('status', LifecycleStatus::Active)
            ->whereNotNull('cluster_id')
            ->pluck('cluster_id');

        return Route::query()
            ->where(static fn (Builder $query): Builder => $query
                ->where('node_id', $node->id)
                ->orWhereIn('cluster_id', $clusters))
            ->orderBy('id')
            ->get();
    }

    /**
     * Every Route is expected to be active. This reads only stored state, so it also runs when the Node is
     * unreachable.
     *
     * @param  Collection<int, Route>  $routes
     * @return list<DoctorIssueData>
     */
    private function lifecycleIssues(Collection $routes): array
    {
        return $routes
            ->reject(static fn (Route $route): bool => $route->status === RouteStatus::Active)
            ->map(static fn (Route $route): DoctorIssueData => new DoctorIssueData(
                RouteDoctorIssueCode::LifecycleNotActive,
                DoctorIssueKind::Drift,
                'route',
                $route->id,
                $route->domain,
                "Route [{$route->domain}] lifecycle is not active.",
                RouteStatus::Active->value,
                $route->status->value,
            ))
            ->values()
            ->all();
    }

    /** @return list<DoctorIssueData> */
    private function issuesFor(
        RouteCustomProxy $proxy,
        Route $route,
        CustomProxyRouteObservation $observation,
    ): array {
        $issues = [];

        foreach ([
            [$observation->dnsMatches, RouteDoctorIssueCode::DnsMismatch, 'DNS', 'match', 'mismatch'],
            [$observation->certificateMatches, RouteDoctorIssueCode::CertificateMismatch, 'certificate', 'present', 'absent'],
            [$observation->caddyMatches, RouteDoctorIssueCode::CaddyMismatch, 'Caddy site', 'present', 'absent'],
            [$observation->upstreamReachable, RouteDoctorIssueCode::UpstreamUnreachable, 'upstream', 'reachable', 'unreachable'],
        ] as [$matches, $code, $label, $expected, $observed]) {
            if ($matches === true) {
                continue;
            }

            if ($matches === null) {
                $issues[] = $this->failure($proxy, $route->domain);

                continue;
            }

            $issues[] = new DoctorIssueData(
                $code,
                DoctorIssueKind::Drift,
                'route',
                $proxy->route_id,
                $route->domain,
                "Custom proxy Route [{$route->domain}] {$label} does not match managed intent.",
                $expected,
                $observed,
            );
        }

        return $issues;
    }

    private function failure(RouteCustomProxy $proxy, ?string $domain): DoctorIssueData
    {
        return new DoctorIssueData(
            RouteDoctorIssueCode::InspectionFailed,
            DoctorIssueKind::Unverifiable,
            'route',
            $proxy->route_id,
            $domain,
            $domain === null
                ? 'Custom proxy Route inspection could not be verified.'
                : "Custom proxy Route [{$domain}] could not be inspected.",
            'verifiable',
            'unverifiable',
        );
    }
}
