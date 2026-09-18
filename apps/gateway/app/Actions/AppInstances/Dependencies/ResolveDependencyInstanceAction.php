<?php

declare(strict_types=1);

namespace App\Actions\AppInstances\Dependencies;

use App\Data\AppInstances\Dependencies\ResolvedDependencyInstanceData;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Routes\RouteDomain;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Support\Facades\DB;

final readonly class ResolveDependencyInstanceAction
{
    public function __construct(private NodeAccessAuthorizer $authorizer) {}

    public function execute(string $domain, Node $consumer): ResolvedDependencyInstanceData
    {
        $domain = RouteDomain::normalize($domain);
        if (! RouteDomain::isValid($domain) || ! str_contains($domain, '.')) {
            throw new ResourceOperationException('dependencies.domain_invalid', 'A full Route domain is required.', 422);
        }

        return DB::transaction(function () use ($domain, $consumer): ResolvedDependencyInstanceData {
            $routes = Route::query()->where('domain', $domain)
                ->whereIn('status', [RouteStatus::Active, RouteStatus::Activating])
                ->with('targets.appInstance.node')->get();
            if ($routes->isEmpty()) {
                $this->notFound();
            }

            foreach ($routes as $route) {
                foreach ($route->targets as $target) {
                    if (! $this->authorizer->allows($consumer, $target->appInstance->node)) {
                        $this->notFound();
                    }
                }
            }

            if ($routes->count() !== 1) {
                throw new ResourceOperationException('dependencies.target_ambiguous', 'The domain does not select one instance.', 409);
            }
            $route = $routes->sole();
            if ($route->targets->isEmpty()) {
                $this->notFound();
            }
            if ($route->targets->count() !== 1) {
                throw new ResourceOperationException('dependencies.target_ambiguous', 'The domain does not select one instance.', 409);
            }
            $instance = $route->targets->sole()->appInstance;
            if ($instance->app_id !== $route->app_id || $instance->status !== AppInstanceState::Active
                || $instance->migration_required || $instance->removalMember()->exists()) {
                throw new ResourceOperationException('dependencies.instance_unavailable', 'The instance is unavailable for dependency inventory.', 409);
            }

            return new ResolvedDependencyInstanceData($domain, $instance->id, $instance->app_id, $instance->node_id, $instance->environment);
        });
    }

    private function notFound(): never
    {
        throw new ResourceOperationException('dependencies.target_not_found', 'No accessible instance matches the domain.', 404);
    }
}
