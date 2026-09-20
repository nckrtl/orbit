<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\Routes\PublicRouteEdgeProjector;
use App\Domain\Routes\PublicRouteEligibility;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RoutePublicPublication;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class PublishPublicRouteAction
{
    public function __construct(
        private PublicRouteEdgeProjector $edge,
        private PublicRouteEligibility $eligibility,
        private DevelopmentProjectionOperationLock $owner,
    ) {}

    public function execute(Route $route, RoutePublication $publication): Route
    {
        return $this->owner->run(fn (): Route => $this->publishOwned($route->id, $publication));
    }

    /**
     * Takes the Route off the public edge and keeps its publication. A Route that is always public
     * uses this before removal, because an active public edge cannot be removed with its Route.
     */
    public function withdraw(Route $route): Route
    {
        return $this->owner->run(function () use ($route): Route {
            $route = Route::query()
                ->with(['targets.appInstance.node', 'cluster.routerAssignment.node', 'cluster.ingressAssignment.node'])
                ->findOrFail($route->id);

            if ($route->public_publication === RoutePublicPublication::Active) {
                $route->update(['public_publication' => RoutePublicPublication::Inactive]);
                $this->edge->removePublicEdge($route);
            }

            $route->update([
                'replacement_step' => null,
                ...($route->status === RouteStatus::Failed ? [] : ['failed_step' => null, 'error_code' => null]),
            ]);

            return $route->refresh()->load('targets');
        });
    }

    private function publishOwned(int $routeId, RoutePublication $publication): Route
    {
        $route = Route::query()
            ->with(['targets.appInstance.node', 'cluster.routerAssignment.node', 'cluster.ingressAssignment.node'])
            ->findOrFail($routeId);

        if ($publication === RoutePublication::Private) {
            return $this->deactivate($route);
        }

        $route->update(['publication' => RoutePublication::Public]);
        $route->refresh();

        if (
            ! in_array($route->status, [RouteStatus::Active, RouteStatus::Activating], true)
            || ! $this->eligibility->canActivate($route)
        ) {
            $route->update([
                'public_publication' => RoutePublicPublication::Inactive,
                'replacement_step' => null,
                'failed_step' => null,
                'error_code' => null,
            ]);

            return $route->refresh()->load('targets');
        }

        $failureStep = RouteReplacementStep::IngressCertificate->value;

        try {
            $this->forward($route, RouteReplacementStep::IngressCertificate, fn () => $this->edge->prepareIngressCertificate($route));
            $failureStep = RouteReplacementStep::IngressCaddy->value;
            $this->forward($route, RouteReplacementStep::IngressCaddy, fn () => $this->edge->stageIngressCaddy($route));
            $failureStep = RouteReplacementStep::PublicEdgeVerified->value;
            $this->forward($route, RouteReplacementStep::PublicEdgeVerified, fn () => $this->edge->verifyPublicEdge($route));
            $failureStep = RouteReplacementStep::PublicActivated->value;
            $this->forward($route, RouteReplacementStep::PublicActivated, function () use ($route): void {
                $this->markActive($route);
                $this->edge->activatePublicHandler($route);
            });
            $failureStep = RouteReplacementStep::IngressFirewall->value;
            $this->forward($route, RouteReplacementStep::IngressFirewall, fn () => $this->edge->prepareIngressFirewall($route));
        } catch (Throwable $exception) {
            $this->recordFailure($route, $failureStep, $this->errorCode($exception));

            if ($this->rank($route->refresh()->replacement_step) < $this->rank(RouteReplacementStep::PublicActivated)) {
                $this->edge->rollbackPublicEdge($route);
                $route->update([
                    'public_publication' => RoutePublicPublication::Inactive,
                ]);
            }

            throw $exception;
        }

        $route->update([
            'replacement_step' => null,
            'failed_step' => null,
            'error_code' => null,
        ]);

        return $route->refresh()->load('targets');
    }

    private function deactivate(Route $route): Route
    {
        if ($route->public_publication === RoutePublicPublication::Active) {
            $route->update(['public_publication' => RoutePublicPublication::Inactive]);
            $this->edge->removePublicEdge($route);
        }

        $route->update([
            'publication' => RoutePublication::Private,
            'public_publication' => RoutePublicPublication::Inactive,
            'replacement_step' => null,
            'failed_step' => null,
            'error_code' => null,
        ]);

        return $route->refresh()->load('targets');
    }

    private function forward(Route $route, RouteReplacementStep $step, callable $operation): void
    {
        if ($this->rank($route->replacement_step) >= $this->rank($step)) {
            return;
        }

        $operation();
        $route->update([
            'replacement_step' => $step,
            'failed_step' => null,
            'error_code' => null,
        ]);
        $route->refresh();
    }

    private function markActive(Route $route): void
    {
        if ($route->status === RouteStatus::Pending) {
            throw new ResourceOperationException(
                errorCode: 'route.publication_inactive',
                message: 'Public publication stays inactive until the Route is active.',
                status: 409,
            );
        }

        DB::transaction(static function () use ($route): void {
            $locked = Route::query()->lockForUpdate()->findOrFail($route->id);
            $locked->update(['public_publication' => RoutePublicPublication::Active]);
            $route->setRawAttributes($locked->refresh()->getAttributes(), true);
        });
    }

    private function recordFailure(Route $route, string $step, string $errorCode): void
    {
        $attributes = ['replacement_step' => RouteReplacementStep::tryFrom($step) ?? $route->replacement_step];

        if ($route->status !== RouteStatus::Active) {
            $attributes['failed_step'] = $step;
            $attributes['error_code'] = $errorCode;
        }

        Route::query()->whereKey($route->id)->update($attributes);
        $route->refresh();
    }

    private function errorCode(Throwable $exception): string
    {
        return property_exists($exception, 'errorCode') && is_string($exception->errorCode)
            ? $exception->errorCode
            : 'route.publication_failed';
    }

    private function rank(?RouteReplacementStep $step): int
    {
        return match ($step) {
            RouteReplacementStep::IngressCertificate => 1,
            RouteReplacementStep::IngressCaddy => 2,
            RouteReplacementStep::PublicEdgeVerified => 3,
            RouteReplacementStep::PublicActivated => 4,
            RouteReplacementStep::IngressFirewall => 5,
            default => 0,
        };
    }
}
