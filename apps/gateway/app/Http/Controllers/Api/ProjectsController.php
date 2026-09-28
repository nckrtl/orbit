<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Projects\CreateProjectAction;
use App\Actions\Projects\ListProjectsAction;
use App\Actions\Projects\RemoveProjectAction;
use App\Actions\Projects\ShowProjectAction;
use App\Actions\Projects\UpdateProjectAction;
use App\Data\Projects\DevelopmentNodeExclusionData;
use App\Data\Projects\ProjectData;
use App\Domain\Projects\DevelopmentNodeExclusion;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Models\Node;
use App\Models\Project;
use App\Models\ProjectNodeExclusion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProjectsController extends Controller
{
    #[RequiresNodeAccess(ServingNode::Collection)]
    public function index(Request $request, ListProjectsAction $action): JsonResponse
    {
        $consumer = $request->user();
        assert($consumer instanceof Node, description: 'Authenticated peer must be a Node.');

        return response()->json([
            'data' => $action
                ->handle($consumer)
                ->map(static fn (Project $project): array => ProjectData::fromModel($project)->toArray())
                ->values()
                ->all(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function store(StoreProjectRequest $request, CreateProjectAction $action): JsonResponse
    {
        $result = $action->execute($request->payload());

        return response()->json(
            [
                'data' => ProjectData::fromModel($result['project'])->toArray(),
                'meta' => $this->meta($request),
            ],
            $result['created'] ? 201 : 200,
        );
    }

    #[RequiresNodeAccess(ServingNode::ProjectOwning)]
    public function show(Request $request, Project $project, ShowProjectAction $action): JsonResponse
    {
        return response()->json([
            'data' => [
                ...ProjectData::fromModel($action->handle($project))->toArray(),
                'excluded_nodes' => app(DevelopmentNodeExclusion::class)->forProject($project)
                    ->map(static fn (ProjectNodeExclusion $exclusion): array => DevelopmentNodeExclusionData::fromModel($exclusion)->toArray())
                    ->values()
                    ->all(),
            ],
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::ProjectOwning)]
    public function update(UpdateProjectRequest $request, Project $project, UpdateProjectAction $action): JsonResponse
    {
        return response()->json([
            'data' => ProjectData::fromModel($action->execute($project, $request->payload()))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::ProjectOwning)]
    public function destroy(Request $request, Project $project, RemoveProjectAction $action): JsonResponse
    {
        return response()->json([
            'data' => ProjectData::fromModel($action->execute($project))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
