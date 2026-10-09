<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\LifecycleStep;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreProjectLifecycleStepRequest;
use App\Http\Requests\Projects\UpdateProjectLifecycleStepRequest;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProjectLifecycleStepsController extends Controller
{
    public function __construct(private readonly ProjectLifecycleStepStore $steps) {}

    #[RequiresNodeAccess(ServingNode::ProjectOwning)]
    public function setupIndex(Request $request, Project $project): JsonResponse
    {
        return $this->index($request, $project, LifecyclePhase::Setup);
    }

    #[RequiresNodeAccess(ServingNode::ProjectOwning)]
    public function setupStore(StoreProjectLifecycleStepRequest $request, Project $project): JsonResponse
    {
        return $this->store($request, $project, LifecyclePhase::Setup);
    }

    #[RequiresNodeAccess(ServingNode::ProjectOwning)]
    public function setupUpdate(UpdateProjectLifecycleStepRequest $request, Project $project, string $step): JsonResponse
    {
        return $this->update($request, $project, LifecyclePhase::Setup, $step);
    }

    #[RequiresNodeAccess(ServingNode::ProjectOwning)]
    public function setupDestroy(Request $request, Project $project, string $step): JsonResponse
    {
        return $this->destroy($request, $project, LifecyclePhase::Setup, $step);
    }

    #[RequiresNodeAccess(ServingNode::ProjectOwning)]
    public function teardownIndex(Request $request, Project $project): JsonResponse
    {
        return $this->index($request, $project, LifecyclePhase::Teardown);
    }

    #[RequiresNodeAccess(ServingNode::ProjectOwning)]
    public function teardownStore(StoreProjectLifecycleStepRequest $request, Project $project): JsonResponse
    {
        return $this->store($request, $project, LifecyclePhase::Teardown);
    }

    #[RequiresNodeAccess(ServingNode::ProjectOwning)]
    public function teardownUpdate(UpdateProjectLifecycleStepRequest $request, Project $project, string $step): JsonResponse
    {
        return $this->update($request, $project, LifecyclePhase::Teardown, $step);
    }

    #[RequiresNodeAccess(ServingNode::ProjectOwning)]
    public function teardownDestroy(Request $request, Project $project, string $step): JsonResponse
    {
        return $this->destroy($request, $project, LifecyclePhase::Teardown, $step);
    }

    private function index(Request $request, Project $project, LifecyclePhase $phase): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                static fn (LifecycleStep $step): array => $step->toArray(),
                $this->steps->ordered($project, $phase),
            ),
            'meta' => $this->meta($request),
        ]);
    }

    private function store(StoreProjectLifecycleStepRequest $request, Project $project, LifecyclePhase $phase): JsonResponse
    {
        return response()->json([
            'data' => $this->steps->create($project, $phase, $request->step(), $request->beforeStep(), $request->afterStep(), $request->rebalance())->toArray(),
            'meta' => $this->meta($request),
        ], 201);
    }

    private function update(
        UpdateProjectLifecycleStepRequest $request,
        Project $project,
        LifecyclePhase $phase,
        string $step,
    ): JsonResponse {
        return response()->json([
            'data' => $this->steps->update(
                $project,
                $phase,
                $step,
                $request->command(),
                $request->timeoutSeconds(),
                $request->beforeStep(),
                $request->afterStep(),
                $request->hasCommand(),
                $request->hasTimeout(),
                $request->rebalance(),
            )->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    private function destroy(Request $request, Project $project, LifecyclePhase $phase, string $step): JsonResponse
    {
        return response()->json([
            'data' => $this->steps->destroy($project, $phase, $step)->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
