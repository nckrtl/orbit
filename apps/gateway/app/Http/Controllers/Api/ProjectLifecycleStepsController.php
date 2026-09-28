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

    #[RequiresNodeAccess(ServingNode::AppOwning)]
    public function setupIndex(Request $request, Project $app): JsonResponse
    {
        return $this->index($request, $app, LifecyclePhase::Setup);
    }

    #[RequiresNodeAccess(ServingNode::AppOwning)]
    public function setupStore(StoreProjectLifecycleStepRequest $request, Project $app): JsonResponse
    {
        return $this->store($request, $app, LifecyclePhase::Setup);
    }

    #[RequiresNodeAccess(ServingNode::AppOwning)]
    public function setupUpdate(UpdateProjectLifecycleStepRequest $request, Project $app, string $step): JsonResponse
    {
        return $this->update($request, $app, LifecyclePhase::Setup, $step);
    }

    #[RequiresNodeAccess(ServingNode::AppOwning)]
    public function setupDestroy(Request $request, Project $app, string $step): JsonResponse
    {
        return $this->destroy($request, $app, LifecyclePhase::Setup, $step);
    }

    #[RequiresNodeAccess(ServingNode::AppOwning)]
    public function teardownIndex(Request $request, Project $app): JsonResponse
    {
        return $this->index($request, $app, LifecyclePhase::Teardown);
    }

    #[RequiresNodeAccess(ServingNode::AppOwning)]
    public function teardownStore(StoreProjectLifecycleStepRequest $request, Project $app): JsonResponse
    {
        return $this->store($request, $app, LifecyclePhase::Teardown);
    }

    #[RequiresNodeAccess(ServingNode::AppOwning)]
    public function teardownUpdate(UpdateProjectLifecycleStepRequest $request, Project $app, string $step): JsonResponse
    {
        return $this->update($request, $app, LifecyclePhase::Teardown, $step);
    }

    #[RequiresNodeAccess(ServingNode::AppOwning)]
    public function teardownDestroy(Request $request, Project $app, string $step): JsonResponse
    {
        return $this->destroy($request, $app, LifecyclePhase::Teardown, $step);
    }

    private function index(Request $request, Project $app, LifecyclePhase $phase): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                static fn (LifecycleStep $step): array => $step->toArray(),
                $this->steps->ordered($app, $phase),
            ),
            'meta' => $this->meta($request),
        ]);
    }

    private function store(StoreProjectLifecycleStepRequest $request, Project $app, LifecyclePhase $phase): JsonResponse
    {
        return response()->json([
            'data' => $this->steps->create($app, $phase, $request->step(), $request->beforeStep(), $request->afterStep())->toArray(),
            'meta' => $this->meta($request),
        ], 201);
    }

    private function update(
        UpdateProjectLifecycleStepRequest $request,
        Project $app,
        LifecyclePhase $phase,
        string $step,
    ): JsonResponse {
        return response()->json([
            'data' => $this->steps->update(
                $app,
                $phase,
                $step,
                $request->command(),
                $request->timeoutSeconds(),
                $request->beforeStep(),
                $request->afterStep(),
                $request->hasCommand(),
                $request->hasTimeout(),
            )->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    private function destroy(Request $request, Project $app, LifecyclePhase $phase, string $step): JsonResponse
    {
        return response()->json([
            'data' => $this->steps->destroy($app, $phase, $step)->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
