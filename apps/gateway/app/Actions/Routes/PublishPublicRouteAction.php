<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\Routes\PublicRouteEdgeProjector;
use App\Domain\Routes\PublicRouteEligibility;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Route;
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
     * uses this before removal, because a live public edge cannot be removed with its Route.
     */
    public function withdraw(Route $route): Route
    {
        return $this->owner->run(function () use ($route): Route {
            $route = Route::query()
                ->with(['targets.appInstance.node', 'cluster.routerAssignment.node', 'cluster.ingressAssignment.node'])
                ->findOrFail($route->id);

            $shouldRemove = $this->eligibility->canActivate($route) || $this->eligibility->publicEdgeIsLive($route);

            if ($route->status !== RouteStatus::Retiring) {
                $route->update(['status' => RouteStatus::Retiring]);
                $route->refresh();
            }

            if ($shouldRemove) {
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
                'replacement_step' => null,
                'failed_step' => null,
                'error_code' => null,
            ]);

            return $route->refresh()->load('targets');
        }

        $failureStep = RouteReplacementStep::IngressCertificate->value;

        try {
            $this->forward($route, RouteReplacementStep::IngressCertificate, fn () => $this->edge->prepareIngressCertificate($route));
            $failureStep = RouteReplacementStep::PublicEdgeVerified->value;
            $this->forward($route, RouteReplacementStep::PublicEdgeVerified, fn () => $this->edge->verifyPublicEdge($route));
            $failureStep = RouteReplacementStep::PublicActivated->value;
            $this->forward($route, RouteReplacementStep::PublicActivated, function () use ($route): void {
                $this->assertReadyForPublicHandler($route);
                $route->update(['replacement_step' => RouteReplacementStep::PublicActivated]);
                $route->refresh();
                $this->edge->activatePublicHandler($route);
            });
            $failureStep = RouteReplacementStep::IngressFirewall->value;
            $this->forward($route, RouteReplacementStep::IngressFirewall, fn () => $this->edge->prepareIngressFirewall($route));
        } catch (Throwable $exception) {
            // A failed activation never completed: the stored step goes back to the verified edge, so the
            // rollback build renders the Ingress Node as it last built and keeps the Node buildable.
            $activationFailed = $failureStep === RouteReplacementStep::PublicActivated->value;
            $this->recordFailure(
                $route,
                $failureStep,
                $this->errorCode($exception),
                $activationFailed ? RouteReplacementStep::PublicEdgeVerified : null,
            );

            if (
                $activationFailed
                || $this->eligibility->publicActivationRank($route->refresh()->replacement_step)
                    < $this->eligibility->publicActivationRank(RouteReplacementStep::PublicActivated)
            ) {
                try {
                    $this->edge->rollbackPublicEdge($route);
                } catch (Throwable $rollback) {
                    // The activation failure is what the caller must see; the rollback retries on the next command.
                    report($rollback);
                }
            }

            throw $exception;
        }

        $route->update([
            'replacement_step' => RouteReplacementStep::IngressFirewall,
            'failed_step' => null,
            'error_code' => null,
        ]);

        return $route->refresh()->load('targets');
    }

    private function deactivate(Route $route): Route
    {
        $shouldRemove = $this->eligibility->canActivate($route) || $this->eligibility->publicEdgeIsLive($route);

        $route->update([
            'publication' => RoutePublication::Private,
            'replacement_step' => null,
            'failed_step' => null,
            'error_code' => null,
        ]);
        $route->refresh();

        if ($shouldRemove) {
            $this->edge->removePublicEdge($route);
        }

        return $route->refresh()->load('targets');
    }

    private function forward(Route $route, RouteReplacementStep $step, callable $operation): void
    {
        if ($this->eligibility->publicActivationRank($route->replacement_step) >= $this->eligibility->publicActivationRank($step)) {
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

    private function assertReadyForPublicHandler(Route $route): void
    {
        if ($route->status === RouteStatus::Pending) {
            throw new ResourceOperationException(
                errorCode: 'route.publication_inactive',
                message: 'A public edge stays unpublished until the Route is active.',
                status: 409,
            );
        }
    }

    private function recordFailure(Route $route, string $step, string $errorCode, ?RouteReplacementStep $stored = null): void
    {
        $attributes = ['replacement_step' => $stored ?? RouteReplacementStep::tryFrom($step) ?? $route->replacement_step];

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
}
