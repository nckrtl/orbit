<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Routes\UpdateRouteAction;
use App\Data\Instances\InstanceData;
use App\Data\Instances\RenameInstanceData;
use App\Data\Routes\UpdateRouteData;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Instances\DevelopmentInstanceBranchInspector;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\InstanceSandboxGuard;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Routes\ReservedPrivateHostname;
use App\Domain\Routes\RouteDomain;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppRuntimeMigration;
use App\Models\Instance;
use App\Models\InstanceAppProjection;
use App\Models\InstanceEnvironmentValue;
use App\Models\InstanceRename;
use App\Models\InstanceTransfer;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class RenameInstanceAction
{
    public function __construct(
        private InstanceEnvironmentOperationLock $environmentOperations,
        private AppDevSourceOperationLock $sourceLock,
        private DevelopmentInstanceBranchInspector $source,
        private UpdateRouteAction $routes,
        private RecordEventBroadcaster $broadcaster,
    ) {}

    public function execute(Instance $instance, RenameInstanceData $data): Instance
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        InstanceAppProjection::assertAvailable([$instance->id]);
        $identity = $this->identity($instance, $data);
        $latest = InstanceRename::query()->where('instance_id', $instance->id)->orderByRaw("phase = 'complete' ASC")->latest('rowid')->first();
        if ($instance->placedOnAppDev() && (! $latest instanceof InstanceRename || $latest->phase === 'complete' && ! $this->matches($latest, $identity))) {
            app(MigrateAppRuntimeAction::class)->execute($instance->node);
        }
        AppRuntimeMigration::assertInstanceAvailable($instance);
        try {
            return $this->environmentOperations->run([$instance->id], fn (): Instance => $this->sourceLock->synchronized(
                $instance->node_id,
                fn (): Instance => $this->renameOwned($instance->refresh(), $data),
            ));
        } catch (ResourceOperationException $exception) {
            if ($exception->errorCode === 'env.operation_busy') {
                throw new ResourceOperationException('instance.lifecycle_busy', 'The Instance is busy with another lifecycle operation.', 409, previous: $exception);
            }
            throw $exception;
        }
    }

    private function renameOwned(Instance $instance, RenameInstanceData $data): Instance
    {
        InstanceAppProjection::assertAvailable([$instance->id]);
        if (! $instance->placedOnAppDev() || $instance->source_layout !== InstanceSourceLayout::Checkout->value) {
            throw new ResourceOperationException('instance.rename_unsupported', 'Rename requires a development checkout Instance.', 409);
        }
        if ($instance->status === InstanceState::Removing
            || InstanceTransfer::query()->where('instance_id', $instance->id)->where('status', '!=', 'completed')->exists()
            || Instance::query()->where('clone_candidate_id', $instance->id)->whereNull('clone_completed_at')->exists()
            || $instance->clone_candidate_id !== null && $instance->clone_completed_at === null) {
            throw new ResourceOperationException('instance.lifecycle_busy', 'The Instance is busy with another lifecycle operation.', 409);
        }
        if ($instance->status !== InstanceState::Active) {
            throw new ResourceOperationException('instance.rename_inactive', 'Rename requires an active Instance.', 409);
        }
        $identity = $this->identity($instance, $data);
        $domain = $identity['domain'];
        $app = $identity['app'];
        $journal = InstanceRename::query()->where('instance_id', $instance->id)->where('phase', '!=', 'complete')->first();
        if ($journal instanceof InstanceRename && ! $this->matches($journal, $identity)) {
            throw new ResourceOperationException('route.domain_change_conflict', 'Retry the identical Instance rename request.', 409);
        }
        if (! $journal instanceof InstanceRename) {
            $last = InstanceRename::query()->where('instance_id', $instance->id)->latest('rowid')->first();
            if ($last instanceof InstanceRename && $this->matches($last, $identity)) {
                return $instance->refresh()->load(['project', 'node', 'routes.targets']);
            }
            if ($instance->routes()->where(static fn ($query) => $query->whereNotNull('replaces_route_id')->orWhereNotNull('replaced_by_route_id')->orWhereNotNull('transition_node_id')->orWhereNotNull('transition_cluster_id'))->exists()) {
                throw new ResourceOperationException('instance.lifecycle_busy', 'Another owner has an unfinished Route replacement.', 409);
            }
            $this->source->assertBranchCheckedOut($instance, $data->branch);
            $route = null;
            if ($domain !== null && $app !== null) {
                ReservedPrivateHostname::assertAvailable($domain);
                $route = $instance->authoritativeRoute($app);
                if (! $route instanceof Route || $route->project_id !== $instance->project_id || $route->targets()->count() !== 1 || $route->targets()->sole()->instance_id !== $instance->id) {
                    throw new ResourceOperationException('instance.route_required', 'Rename requires the Instance’s own single-target Project Route.', 409);
                }
                if (Route::query()->where('domain', $domain)->where('id', '!=', $route->id)->exists()) {
                    throw new ResourceOperationException('route.domain_conflict', 'The domain is already reserved.', 409);
                }
            }
            $journal = InstanceRename::query()->create([...$identity, 'id' => (string) Str::uuid(), 'instance_id' => $instance->id, 'source_route_id' => $route?->id, 'phase' => 'requested']);
        } elseif ($journal->branch_supplied) {
            $this->source->assertBranchCheckedOut($instance, $journal->branch);
        }
        if ($journal->phase === 'requested') {
            if ($journal->domain !== null && $journal->app !== null) {
                $instance->unsetRelation('routes');
                $current = $instance->authoritativeRoute($journal->app);
                $unfinished = $instance->routes()->where('routes.app', $journal->app)->whereNotNull('replaces_route_id')->exists();
                // Cleanup may have succeeded before its response or our checkpoint was saved.
                if ($current?->domain !== $journal->domain || $unfinished) {
                    $original = Route::query()->find($journal->source_route_id);
                    if (! $original instanceof Route) {
                        throw new ResourceOperationException('route.domain_change_conflict', 'The recorded rename Route is unavailable.', 409);
                    }
                    $this->routes->execute($original, new UpdateRouteData(true, $journal->domain, false, null), allowGenerated: true, renameOwner: $journal);
                }
            }
            $journal->update(['phase' => 'domain_converged']);
        }
        if ($journal->domain !== null && $journal->app !== null) {
            $instance->unsetRelation('routes');
            if ($instance->authoritativeRoute($journal->app)?->domain !== $journal->domain) {
                throw new ResourceOperationException('route.domain_change_conflict', 'Recover the recorded rename Route before completing the Instance rename.', 409);
            }
        }
        DB::transaction(function () use ($instance, $journal): void {
            if ($journal->domain !== null && $journal->app !== null && $instance->runtimeForApp($journal->app)['laravel'] === true) {
                InstanceEnvironmentValue::query()->updateOrCreate(
                    ['instance_id' => $instance->id, 'app' => $journal->app, 'env_key' => 'APP_URL'],
                    ['env_value' => "https://{$journal->domain}"],
                );
            }
            if ($journal->branch_supplied && $instance->branch !== $journal->branch) {
                $instance->update(['branch' => $journal->branch, 'branch_override' => $journal->branch]);
            }
            $journal->update(['phase' => 'complete']);
        });
        $instance->refresh()->load(['project', 'node', 'routes.targets']);
        $this->broadcaster->broadcast(RecordEventType::InstanceUpdated, $instance->id, InstanceData::fromModel($instance)->toArray());

        return $instance;
    }

    /** @return array{app: ?string, domain: ?string, branch_supplied: bool, branch: ?string} */
    private function identity(Instance $instance, RenameInstanceData $data): array
    {
        $domain = $data->domain === null ? null : RouteDomain::validate($data->domain);

        return ['app' => $domain === null ? null : $instance->appConfiguration($data->app)['name'], 'domain' => $domain, 'branch_supplied' => $data->branch !== null, 'branch' => $data->branch];
    }

    /** @param array{app: ?string, domain: ?string, branch_supplied: bool, branch: ?string} $identity */
    private function matches(InstanceRename $journal, array $identity): bool
    {
        return $journal->app === $identity['app'] && $journal->domain === $identity['domain']
            && $journal->branch_supplied === $identity['branch_supplied'] && $journal->branch === $identity['branch'];
    }
}
