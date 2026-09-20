<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Actions\Routes\PublishPublicRouteAction;
use App\Data\Analytics\InstanceAnalyticsData;
use App\Data\Routes\RouteData;
use App\Domain\Analytics\AnalyticsTrackingHosts;
use App\Domain\Analytics\AnalyticsTrackingRouteProjector;
use App\Domain\Analytics\AnalyticsTrackingUpstream;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RoutePublicPublication;
use App\Domain\Routes\RouteStateResolver;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class EnableInstanceAnalyticsAction
{
    public function __construct(
        private ShowInstanceAnalyticsAction $show,
        private DisableInstanceAnalyticsAction $disable,
        private AnalyticsTrackingRouteProjector $projection,
        private PublishPublicRouteAction $publication,
        private RouteStateResolver $state,
        private AppInstanceEnvironmentOperationLock $operations,
        private RecordEventBroadcaster $broadcaster,
    ) {}

    /** @param list<string> $hosts The exact host set; empty means the default host. */
    public function execute(AppInstance $instance, array $hosts): InstanceAnalyticsData
    {
        return $this->operations->run(
            [$instance->id],
            fn (): InstanceAnalyticsData => $this->executeOwned($instance, $hosts),
        );
    }

    /** @param list<string> $hosts */
    private function executeOwned(AppInstance $instance, array $hosts): InstanceAnalyticsData
    {
        if (! AnalyticsTrackingUpstream::node() instanceof Node) {
            throw new ResourceOperationException(
                errorCode: 'analytics.role_missing',
                message: 'A tracking host needs a Node with an active analytics role.',
                status: 409,
            );
        }

        $public = $this->publicRoute($instance);
        $clusterId = (int) $public->cluster_id;
        $this->state->assertRouter($clusterId);
        $hosts = $hosts === [] ? [AnalyticsTrackingHosts::defaultFor($public->domain)] : $hosts;
        $current = $this->show->trackingRoutes($instance)->keyBy('domain');

        foreach ($hosts as $host) {
            if (! $current->has($host) && Route::query()->where('domain', $host)->exists()) {
                throw $this->hostTaken($host);
            }
        }

        foreach ($hosts as $host) {
            $route = $current->get($host) ?? $this->create($instance, $clusterId, $host);
            $this->converge($route);
        }

        foreach ($current->reject(static fn (Route $route): bool => in_array($route->domain, $hosts, true)) as $route) {
            $this->disable->removeRoute($route);
        }

        return $this->show->execute($instance);
    }

    /** The tracking host follows the instance's own public Route, so it takes that Route's cluster. */
    private function publicRoute(AppInstance $instance): Route
    {
        $route = $instance->unsetRelation('routes')->authoritativeRoute();

        if (
            ! $route instanceof Route
            || $route->publication !== RoutePublication::Public
            || $route->cluster_id === null
        ) {
            throw new ResourceOperationException(
                errorCode: 'analytics.public_domain_required',
                message: 'A tracking host needs an App instance with a public domain.',
                status: 422,
            );
        }

        return $route;
    }

    private function create(AppInstance $instance, int $clusterId, string $host): Route
    {
        try {
            /** @var Route $route */
            $route = DB::transaction(static function () use ($instance, $clusterId, $host): Route {
                $route = Route::query()->create([
                    'kind' => RouteKind::AnalyticsTracking,
                    'app_id' => null,
                    'node_id' => null,
                    'cluster_id' => $clusterId,
                    'generation_basis_node_id' => null,
                    'domain' => $host,
                    'provenance' => RouteProvenance::Explicit,
                    'publication' => RoutePublication::Public,
                    'status' => RouteStatus::Pending,
                    'failed_step' => null,
                    'error_code' => null,
                ]);
                $route->analyticsTracking()->create(['app_instance_id' => $instance->id]);

                return $route;
            });
        } catch (QueryException $exception) {
            throw $this->hostTaken($host, $exception);
        }

        $this->broadcaster->broadcast(
            RecordEventType::RouteCreated,
            $route->id,
            RouteData::fromModel($route)->toArray(),
        );

        return $route;
    }

    /** Runs again for a host that an earlier request left pending, failed, or off the public edge. */
    private function converge(Route $route): void
    {
        if (! $route->isAuthoritative()) {
            try {
                $this->projection->converge($route);
                $route->update(['status' => RouteStatus::Active, 'failed_step' => null, 'error_code' => null]);
            } catch (Throwable $exception) {
                $route->update([
                    'status' => RouteStatus::Failed,
                    'failed_step' => 'projection',
                    'error_code' => property_exists($exception, 'errorCode') && is_string($exception->errorCode)
                        ? $exception->errorCode
                        : 'route.projection_failed',
                ]);

                throw $exception;
            }
        }

        if ($route->public_publication === RoutePublicPublication::Active && $route->replacement_step === null) {
            return;
        }

        $published = $this->publication->execute($route, RoutePublication::Public);
        $this->broadcaster->broadcast(
            RecordEventType::RouteUpdated,
            $published->id,
            RouteData::fromModel($published)->toArray(),
        );
    }

    private function hostTaken(string $host, ?Throwable $previous = null): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'analytics.host_taken',
            message: "Host [{$host}] is already the domain of another Route.",
            status: 409,
            previous: $previous,
        );
    }
}
