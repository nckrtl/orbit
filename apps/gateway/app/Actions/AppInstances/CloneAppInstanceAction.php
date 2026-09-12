<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Data\AppInstances\CloneAppInstanceData;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppInstances\AppInstanceCloneCandidateInspector;
use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\CloneCandidateSource;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\AppInstances\ProductionAppInstanceSourceLifecycle;
use App\Domain\AppInstances\ProductionCloneRouteProjector;
use App\Domain\AppInstances\ProductionPhpRuntimeIdentity;
use App\Domain\AppInstances\ProductionRouteProjector;
use App\Domain\AppInstances\Sqlite\AppInstanceSqliteSeeder;
use App\Domain\AppInstances\Sqlite\SqliteSeedPlacement;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteHostname;
use App\Domain\Routes\RoutePlacement;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStateResolver;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppProd\AppProdSiteRepository;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class CloneAppInstanceAction
{
    public function __construct(
        private AppInstanceCloneCandidateInspector $candidates,
        private AppInstanceEnvironmentOperationLock $environmentOperations,
        private AppDevSourceOperationLock $sourceLock,
        private ProductionAppInstanceSourceLifecycle $source,
        private RouteStateResolver $routeState,
        private AppProdSiteRepository $appProdSites,
        private CloneAppInstanceEnvironmentAction $environment,
        private AppInstanceSqliteSeeder $sqlite,
        private InstantiateAppRuntimeDefinitionsAction $definitions,
        private ProductionRouteProjector $projection,
        private ProductionCloneRouteProjector $cloneProjection,
        private DevelopmentProjectionOperationLock $projectionOwner,
    ) {}

    /** @return array{appInstance: AppInstance, created: bool} */
    public function execute(AppInstance $candidate, CloneAppInstanceData $data): array
    {
        $candidate->loadMissing(['app', 'node']);
        $existing = $this->existingTarget($candidate, $data);

        if ($existing instanceof AppInstance && $existing->clone_completed_at !== null) {
            return [
                'appInstance' => $existing->load('routes.targets'),
                'created' => false,
            ];
        }

        $node = Node::query()->findOrFail($data->nodeId);
        $branch = $data->branch ?? $this->candidateBranch($candidate);

        if ($existing instanceof AppInstance) {
            $source = $this->candidates->inspect($candidate, $branch);
            $target = $existing;
            $created = false;
        } else {
            [$target, $source] = $this->environmentOperations->run(
                [$candidate->id],
                function () use ($candidate, $data, $node, $branch): array {
                    $source = $this->candidates->inspect($candidate, $branch);
                    [$hostname, $placement] = $this->preflight($candidate, $node, $data);

                    return [$this->reserve($candidate, $node, $data, $source, $hostname, $placement), $source];
                },
            );
            $created = true;
        }

        try {
            $result = $this->environmentOperations->run(
                [$candidate->id, $target->id],
                fn (): AppInstance => $this->sourceLock->synchronized(
                    $target->node_id,
                    fn (): AppInstance => $this->resume($candidate, $target, $data, $source, $created),
                ),
            );
        } catch (Throwable $exception) {
            $this->recordFailure($target, $exception);

            throw $exception;
        }

        return ['appInstance' => $result, 'created' => $created];
    }

    private function existingTarget(AppInstance $candidate, CloneAppInstanceData $data): ?AppInstance
    {
        $existing = AppInstance::query()
            ->where('app_id', $candidate->app_id)
            ->where('name', $data->name)
            ->first();

        if (! $existing instanceof AppInstance) {
            return null;
        }

        if (
            $existing->clone_candidate_id !== $candidate->id
            || $existing->node_id !== $data->nodeId
            || $existing->environment !== 'production'
            || $existing->clone_preview_name !== $data->previewName
            || $existing->clone_requested_branch !== $data->branch
            || $existing->clone_sqlite_source_path !== $data->sqliteSourcePath
        ) {
            throw $this->conflict(
                'instance.clone_retry_conflict',
                'The target AppInstance already exists with different immutable clone input.',
            );
        }

        if ($existing->status === AppInstanceState::Removing) {
            throw $this->conflict('instance.removal_conflict', 'The target AppInstance is being removed.');
        }

        return $existing;
    }

    private function candidateBranch(AppInstance $candidate): string
    {
        $branch = $candidate->environment === 'production'
            ? $candidate->deployment_branch ?? $candidate->branch
            : $candidate->branch;

        if (! is_string($branch) || $branch === '') {
            throw $this->conflict(
                'instance.clone_candidate_branch_invalid',
                'The candidate has no configured branch to inherit.',
            );
        }

        return $branch;
    }

    /** @return array{string, RoutePlacement} */
    private function preflight(AppInstance $candidate, Node $node, CloneAppInstanceData $data): array
    {
        $candidate->refresh()->loadMissing(['app', 'node']);
        $node->refresh();
        $placement = $this->assertPlacement($candidate, $node);

        if (! is_string($node->tld) || $node->tld === '') {
            throw $this->conflict('route.tld_required', 'A clone preview requires the destination Node TLD.');
        }

        $hostname = RouteHostname::validate("{$data->previewName}.{$node->tld}");

        if (Route::query()->where('hostname', $hostname)->exists()) {
            throw $this->conflict('route.hostname_conflict', "Route hostname [{$hostname}] is already owned.");
        }

        if (AppInstance::query()->where('app_id', $candidate->app_id)->where('name', $data->name)->exists()) {
            throw $this->conflict('instance.placement_conflict', 'The target AppInstance name is already owned.');
        }

        return [$hostname, $placement];
    }

    private function assertPlacement(AppInstance $candidate, Node $node): RoutePlacement
    {
        if ($node->status !== LifecycleStatus::Active || $node->platform !== 'linux') {
            throw $this->conflict('instance.node_inactive', 'The selected app-prod Node is not active.');
        }

        if (! $node->roles()->where('role', RoleName::AppProd)->where('status', LifecycleStatus::Active)->exists()) {
            throw $this->conflict('instance.node_not_app_prod', 'The selected Node has no active app-prod role.');
        }

        $placement = $this->routeState->forNode($node);

        if ($placement->clusterId !== null) {
            $this->routeState->assertRouter($placement->clusterId);
        }

        if ($this->appProdSites->hasLivePublicFootprint($node)) {
            throw $this->conflict(
                'instance.legacy_production_conflict',
                'The selected Node still serves a legacy public production Instance.',
            );
        }

        if (AppInstance::query()
            ->where('app_id', $candidate->app_id)
            ->where('node_id', $node->id)
            ->where('environment', 'production')
            ->exists()) {
            throw $this->conflict(
                'instance.production_placement_conflict',
                'The App already has a production AppInstance on the selected Node.',
            );
        }

        return $placement;
    }

    private function reserve(
        AppInstance $candidate,
        Node $node,
        CloneAppInstanceData $data,
        CloneCandidateSource $source,
        string $hostname,
        RoutePlacement $placement,
    ): AppInstance {
        $user = "orbit-app-{$candidate->app_id}";
        $home = "/home/{$user}";

        try {
            /** @var AppInstance $target */
            $target = DB::transaction(function () use (
                $candidate,
                $node,
                $data,
                $source,
                $hostname,
                $placement,
                $user,
                $home,
            ): AppInstance {
                if (Route::query()->where('hostname', $hostname)->lockForUpdate()->exists()) {
                    throw $this->conflict(
                        'route.hostname_conflict',
                        "Route hostname [{$hostname}] is already owned.",
                    );
                }

                $target = AppInstance::query()->create([
                    'app_id' => $candidate->app_id,
                    'node_id' => $node->id,
                    'name' => $data->name,
                    'environment' => 'production',
                    'source_layout' => AppInstanceSourceLayout::Checkout,
                    'checkout_path' => "{$home}/releases/initial",
                    'production_user' => $user,
                    'production_home' => $home,
                    'root' => $candidate->root,
                    'branch' => $data->branch ?? $source->branch,
                    'branch_override' => $data->branch,
                    'clone_candidate_id' => $candidate->id,
                    'clone_candidate_commit' => $source->commit,
                    'clone_requested_branch' => $data->branch,
                    'clone_preview_name' => $data->previewName,
                    'clone_preview_hostname' => $hostname,
                    'clone_sqlite_source_path' => $data->sqliteSourcePath,
                    'provisioning_step' => 'clone-reserved',
                    'status' => AppInstanceState::Reserved,
                ]);
                $route = Route::query()->create([
                    'app_id' => $candidate->app_id,
                    'node_id' => $placement->nodeId,
                    'cluster_id' => $placement->clusterId,
                    'generation_basis_node_id' => null,
                    'hostname' => $hostname,
                    'provenance' => RouteProvenance::Explicit,
                    'publication' => RoutePublication::Private,
                    'status' => RouteStatus::Pending,
                ]);
                $route->targets()->create([
                    'app_instance_id' => $target->id,
                    'position' => 0,
                ]);

                return $target;
            });
        } catch (QueryException $exception) {
            throw new ResourceOperationException(
                errorCode: 'instance.clone_reservation_conflict',
                message: 'The target AppInstance or preview Route is already owned.',
                status: 409,
                previous: $exception,
            );
        }

        return $target;
    }

    private function resume(
        AppInstance $candidate,
        AppInstance $target,
        CloneAppInstanceData $data,
        CloneCandidateSource $expectedSource,
        bool $created,
    ): AppInstance {
        $target->refresh()->loadMissing(['app', 'node', 'routes.targets']);
        $route = $this->cloneRoute($target);

        if ($route->status === RouteStatus::Failed) {
            $route->update([
                'status' => RouteStatus::Pending,
                'failed_step' => null,
                'error_code' => null,
            ]);
        }

        $currentSource = $this->candidates->inspect($candidate, (string) $target->branch);
        $this->assertCandidateUnchanged($expectedSource, $currentSource);

        if ($target->provisioning_step === 'clone-reserved') {
            $this->source->prepareUser($target);
            $this->checkpoint($target, 'clone-user-prepared');
        }

        if ($target->provisioning_step === 'clone-user-prepared') {
            $this->source->prepareSource($target, ! $created);
            $this->checkpoint($target, 'clone-source-prepared', AppInstanceState::CheckoutPrepared);
        }

        if ($target->provisioning_step === 'clone-source-prepared') {
            $resolution = $this->source->resolve($target);

            if (
                $resolution->branch !== $target->branch
                || preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $resolution->startingCommit) !== 1
            ) {
                throw $this->conflict('instance.source_identity_invalid', 'Resolved source identity is invalid.');
            }

            $this->checkpoint($target, 'clone-source-resolved', AppInstanceState::SourceResolved, [
                'starting_commit' => $resolution->startingCommit,
            ]);
        }

        if ($target->provisioning_step === 'clone-source-resolved') {
            $profile = $this->source->inspectProfile($target);
            $runtime = is_string($profile->phpVersion)
                ? ProductionPhpRuntimeIdentity::forProvisioning($target, $profile->phpVersion)->attributes()
                : [];
            $this->checkpoint($target, 'clone-source-classified', attributes: [
                'selected_php_version' => $profile->phpVersion,
                'source_is_laravel' => $profile->laravel,
                ...$runtime,
            ]);
        }

        if ($target->provisioning_step === 'clone-source-classified') {
            $this->source->prepareCaddyAccess($target);
            $this->checkpoint($target, 'clone-caddy-access-prepared');
        }

        if ($target->provisioning_step === 'clone-caddy-access-prepared') {
            $this->environment->execute($candidate, $target);
            $this->checkpoint($target, 'clone-environment-synchronized');
        }

        if ($target->provisioning_step === 'clone-environment-synchronized') {
            $this->prepareSqlite($currentSource, $target, $data);
            $this->checkpoint($target, 'clone-sqlite-prepared');
        }

        if ($target->provisioning_step === 'clone-sqlite-prepared') {
            $this->definitions->execute($target);
            $this->checkpoint($target, 'clone-definitions-instantiated');
        }

        if ($target->provisioning_step === 'clone-definitions-instantiated') {
            $this->prepareRuntime($target, $route);
            $this->checkpoint($target, 'clone-runtime-prepared');
        }

        if ($target->provisioning_step === 'clone-runtime-prepared') {
            $this->projection->prepareCertificate($target, $route);
            $this->checkpoint($target, 'clone-certificate-prepared');
        }

        if ($target->provisioning_step === 'clone-certificate-prepared') {
            $this->projection->prepareFirewall($target);
            $this->checkpoint($target, 'clone-firewall-prepared');
        }

        if ($target->provisioning_step === 'clone-firewall-prepared') {
            $this->cloneProjection->prepareWorkloadCaddy($target, $route);
            $this->checkpoint($target, 'clone-workload-caddy-published');
        }

        if ($target->provisioning_step === 'clone-workload-caddy-published') {
            $this->cloneProjection->prepareRouterCertificate($target, $route);
            $this->checkpoint($target, 'clone-router-certificate-prepared');
        }

        if ($target->provisioning_step === 'clone-router-certificate-prepared') {
            $this->cloneProjection->prepareRouteFirewall($target, $route);
            $this->checkpoint($target, 'clone-route-firewall-prepared');
        }

        if ($target->provisioning_step === 'clone-route-firewall-prepared') {
            $this->cloneProjection->verifyWorkload($target, $route);
            $this->checkpoint($target, 'clone-workload-verified');
        }

        if ($target->provisioning_step === 'clone-workload-verified') {
            $this->cloneProjection->prepareRouterCaddy($target, $route);
            $this->checkpoint($target, 'clone-router-caddy-published');
        }

        if (in_array($target->provisioning_step, [
            'clone-router-caddy-published',
            'clone-dns-published',
        ], strict: true)) {
            $this->completePublication($target->id, $route->id);
        }

        return $target->refresh()->load('routes.targets');
    }

    private function cloneRoute(AppInstance $target): Route
    {
        $target->loadMissing('node');
        $placement = $this->routeState->forNode($target->node);

        if ($placement->clusterId !== null) {
            $this->routeState->assertRouter($placement->clusterId);
        }

        $routes = Route::query()
            ->whereHas('targets', static fn ($query) => $query->where('app_instance_id', $target->id))
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($routes->count() !== 1) {
            throw $this->conflict('instance.lifecycle_conflict', 'The clone preview Route changed.');
        }

        $route = $routes->sole();

        if (
            $route->hostname !== $target->clone_preview_hostname
            || $route->provenance !== RouteProvenance::Explicit
            || $route->publication !== RoutePublication::Private
            || $route->node_id !== $placement->nodeId
            || $route->cluster_id !== $placement->clusterId
        ) {
            throw $this->conflict('instance.lifecycle_conflict', 'The clone preview Route changed.');
        }

        return $route;
    }

    private function assertCandidateUnchanged(
        CloneCandidateSource $expected,
        CloneCandidateSource $current,
    ): void {
        if (
            $expected->appInstanceId !== $current->appInstanceId
            || $expected->environment !== $current->environment
            || $expected->basePath !== $current->basePath
            || $expected->executionUser !== $current->executionUser
            || $expected->branch !== $current->branch
            || $expected->commit !== $current->commit
            || $expected->node->id !== $current->node->id
        ) {
            throw $this->conflict(
                'instance.clone_candidate_changed',
                'The candidate source changed after target reservation.',
            );
        }
    }

    private function prepareSqlite(
        CloneCandidateSource $source,
        AppInstance $target,
        CloneAppInstanceData $data,
    ): void {
        if ($data->sqliteSourcePath === null) {
            return;
        }

        $target->loadMissing('node');
        $home = $target->production_home;
        $user = $target->production_user;

        if (! is_string($home) || ! is_string($user)) {
            throw $this->conflict('instance.clone_target_invalid', 'The clone target placement is invalid.');
        }

        $result = $this->sqlite->seed(
            new SqliteSeedPlacement(
                appInstanceId: $source->appInstanceId,
                environment: $source->environment,
                basePath: $source->basePath,
                executionUser: $source->executionUser,
                node: $source->node,
            ),
            new SqliteSeedPlacement(
                appInstanceId: $target->id,
                environment: 'production',
                basePath: $home,
                executionUser: $user,
                node: $target->node,
            ),
            $data->sqliteSourcePath,
        );

        if (! $result->confirmed || ! is_bool($result->changed)) {
            throw $this->conflict(
                'instance.clone_sqlite_unconfirmed',
                'The target SQLite seed result is unconfirmed. Retry the request.',
            );
        }
    }

    private function prepareRuntime(AppInstance $target, Route $route): void
    {
        if ($target->selected_php_version === null) {
            return;
        }

        ProductionPhpRuntimeIdentity::from($target);
        $this->projection->prepareRuntime($target, $route);
    }

    private function completePublication(int $targetId, int $routeId): void
    {
        $this->projectionOwner->run(function () use ($targetId, $routeId): void {
            $target = AppInstance::query()->with('node')->findOrFail($targetId);
            $route = Route::query()->with('targets')->findOrFail($routeId);
            $validatedRoute = $this->cloneRoute($target);

            if ($validatedRoute->id !== $route->id) {
                throw $this->conflict('instance.lifecycle_conflict', 'The clone preview Route changed.');
            }

            if ($target->provisioning_step === 'clone-router-caddy-published') {
                $this->cloneProjection->prepareDns($route);
                $this->checkpoint($target, 'clone-dns-published');
            }

            if ($target->provisioning_step !== 'clone-dns-published') {
                throw $this->conflict('instance.lifecycle_conflict', 'The clone publication lifecycle changed.');
            }

            $this->prepareRuntime($target, $route);
            $this->projection->prepareCertificate($target, $route);
            $this->projection->prepareFirewall($target);
            $this->cloneProjection->prepareWorkloadCaddy($target, $route);
            $this->cloneProjection->prepareRouterCertificate($target, $route);
            $this->cloneProjection->prepareRouteFirewall($target, $route);
            $this->cloneProjection->verifyWorkload($target, $route);
            $this->cloneProjection->prepareRouterCaddy($target, $route);
            $this->cloneProjection->prepareDns($route);

            DB::transaction(function () use ($target, $route): void {
                $lockedTarget = AppInstance::query()->with('node')->lockForUpdate()->findOrFail($target->id);
                $lockedRoute = Route::query()->with('targets')->lockForUpdate()->findOrFail($route->id);
                $placement = $this->routeState->forNode($lockedTarget->node);

                if ($placement->clusterId !== null) {
                    $this->routeState->assertRouter($placement->clusterId);
                }

                if (
                    $lockedTarget->provisioning_step !== 'clone-dns-published'
                    || $lockedTarget->status !== AppInstanceState::SourceResolved
                    || $lockedTarget->clone_completed_at !== null
                    || $lockedRoute->status !== RouteStatus::Pending
                    || $lockedRoute->targets->count() !== 1
                    || $lockedRoute->targets->sole()->app_instance_id !== $lockedTarget->id
                    || $lockedRoute->hostname !== $lockedTarget->clone_preview_hostname
                    || $lockedRoute->node_id !== $placement->nodeId
                    || $lockedRoute->cluster_id !== $placement->clusterId
                ) {
                    throw $this->conflict('instance.lifecycle_conflict', 'The clone lifecycle changed before activation.');
                }

                $lockedRoute->update([
                    'status' => RouteStatus::Active,
                    'failed_step' => null,
                    'error_code' => null,
                ]);
                $lockedTarget->update([
                    'status' => AppInstanceState::Active,
                    'provisioning_step' => 'active',
                    'failed_step' => null,
                    'error_code' => null,
                    'clone_completed_at' => now(),
                ]);
            });
        });
    }

    /** @param array<string, mixed> $attributes */
    private function checkpoint(
        AppInstance $target,
        string $step,
        ?AppInstanceState $status = null,
        array $attributes = [],
    ): void {
        $target->update([
            ...$attributes,
            'provisioning_step' => $step,
            'failed_step' => null,
            'error_code' => null,
            ...($status instanceof AppInstanceState ? ['status' => $status] : []),
        ]);
        $target->refresh();
    }

    private function recordFailure(AppInstance $target, Throwable $exception): void
    {
        $step = property_exists($exception, 'step') && is_string($exception->step)
            ? $exception->step
            : $target->refresh()->provisioning_step ?? 'clone-provisioning';
        $errorCode = property_exists($exception, 'errorCode') && is_string($exception->errorCode)
            ? $exception->errorCode
            : 'instance.clone_failed';

        DB::transaction(static function () use ($target, $step, $errorCode): void {
            AppInstance::query()->whereKey($target->id)->update([
                'failed_step' => $step,
                'error_code' => $errorCode,
            ]);
            Route::query()
                ->whereHas('targets', static fn ($query) => $query->where('app_instance_id', $target->id))
                ->where('status', '<>', RouteStatus::Active->value)
                ->update([
                    'status' => RouteStatus::Failed,
                    'failed_step' => $step,
                    'error_code' => $errorCode,
                ]);
        });
    }

    private function conflict(string $errorCode, string $message): ResourceOperationException
    {
        return new ResourceOperationException($errorCode, $message, 409);
    }
}
