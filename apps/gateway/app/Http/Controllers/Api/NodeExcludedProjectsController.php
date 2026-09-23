<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Data\Projects\DevelopmentNodeExclusionData;
use App\Data\Projects\DevelopmentNodeExclusionResultData;
use App\Domain\Projects\DevelopmentNodeExclusion;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Models\App as OrbitApp;
use App\Models\Node;
use App\Models\ProjectNodeExclusion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NodeExcludedProjectsController extends Controller
{
    public function __construct(private readonly DevelopmentNodeExclusion $exclusions) {}

    #[RequiresNodeAccess(ServingNode::Target)]
    public function index(Request $request, Node $node): JsonResponse
    {
        return response()->json([
            'data' => $this->exclusions->forNode($node)
                ->map(static fn (ProjectNodeExclusion $exclusion): array => DevelopmentNodeExclusionData::fromModel($exclusion)->toArray())
                ->values()
                ->all(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::Target)]
    public function store(Request $request, Node $node, OrbitApp $app): JsonResponse
    {
        $result = $this->exclusions->add($app, $node);

        return response()->json([
            'data' => DevelopmentNodeExclusionResultData::fromModel($result['exclusion'], ! $result['created'])->toArray(),
            'meta' => $this->meta($request),
        ], $result['created'] ? 201 : 200);
    }

    #[RequiresNodeAccess(ServingNode::Target)]
    public function destroy(Request $request, Node $node, OrbitApp $app): JsonResponse
    {
        return response()->json([
            'data' => DevelopmentNodeExclusionData::fromModel($this->exclusions->remove($app, $node))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
