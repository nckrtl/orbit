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
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use App\Models\InstanceTransfer;
use App\Models\Route;

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
        $this->source->assertBranchCheckedOut($instance, $data->branch);

        if ($data->domain !== null) {
            ReservedPrivateHostname::assertAvailable($data->domain);
            $routes = $instance->routes()->with('targets')->get();
            // During replacement both Routes own the same target. Resume through its original Route.
            $roots = $routes->filter(static fn (Route $route): bool => $route->replaces_route_id === null);
            $route = $roots->count() === 1 ? $roots->sole() : null;
            if (! $route instanceof Route || ! $route->isApp() || $route->project_id !== $instance->project_id
                || $route->targets->count() !== 1 || $route->targets->sole()->instance_id !== $instance->id
                || $routes->contains(static fn (Route $other): bool => $other->id !== $route->id && $other->replaces_route_id !== $route->id)) {
                throw new ResourceOperationException('instance.route_required', 'Rename requires the Instance’s own single-target Project Route.', 409);
            }
            $moved = $this->routes->execute($route, new UpdateRouteData(true, $data->domain, false, null), allowGenerated: true);
            if ($instance->source_is_laravel === true) {
                InstanceEnvironmentValue::query()->updateOrCreate(
                    ['instance_id' => $instance->id, 'env_key' => 'APP_URL'],
                    ['env_value' => "https://{$moved->domain}"],
                );
            }
        }
        if ($data->branch !== null && $instance->branch !== $data->branch) {
            $instance->update(['branch' => $data->branch, 'branch_override' => $data->branch]);
        }
        $instance->refresh()->load(['project', 'node', 'routes.targets']);
        $this->broadcaster->broadcast(RecordEventType::InstanceUpdated, $instance->id, InstanceData::fromModel($instance)->toArray());

        return $instance;
    }
}
