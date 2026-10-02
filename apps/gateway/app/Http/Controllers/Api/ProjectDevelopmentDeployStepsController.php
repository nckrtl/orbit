<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Projects\DevelopmentDeployStep;
use App\Domain\Projects\ProjectDevelopmentDeployStepStore;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreProjectDevelopmentDeployStepRequest;
use App\Http\Requests\Projects\UpdateProjectDevelopmentDeployStepRequest;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProjectDevelopmentDeployStepsController extends Controller
{
    public function __construct(private readonly ProjectDevelopmentDeployStepStore $steps) {}

    #[RequiresNodeAccess(ServingNode::ProjectOwning)]
    public function index(Request $request, Project $project): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                static fn (DevelopmentDeployStep $step): array => $step->toArray(),
                $this->steps->ordered($project),
            ),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::ProjectOwning)]
    public function store(StoreProjectDevelopmentDeployStepRequest $request, Project $project): JsonResponse
    {
        return response()->json([
            'data' => $this->steps->create($project, $request->step(), $request->beforeStep(), $request->afterStep())->toArray(),
            'meta' => $this->meta($request),
        ], 201);
    }

    #[RequiresNodeAccess(ServingNode::ProjectOwning)]
    public function update(
        UpdateProjectDevelopmentDeployStepRequest $request,
        Project $project,
        string $step,
    ): JsonResponse {
        return response()->json([
            'data' => $this->steps->update(
                $project,
                $step,
                $request->command(),
                $request->timeoutSeconds(),
                $request->beforeStep(),
                $request->afterStep(),
                $request->hasCommand(),
                $request->hasTimeout(),
                $request->required(),
            )->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::ProjectOwning)]
    public function destroy(Request $request, Project $project, string $step): JsonResponse
    {
        return response()->json([
            'data' => $this->steps->destroy($project, $step)->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
