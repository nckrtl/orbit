<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Routes\ClearRouteTargetAction;
use App\Actions\Routes\CreateRouteAction;
use App\Actions\Routes\ListRoutesAction;
use App\Actions\Routes\RemoveRouteAction;
use App\Actions\Routes\SetRouteTargetAction;
use App\Actions\Routes\ShowRouteAction;
use App\Actions\Routes\UpdateRouteAction;
use App\Data\Routes\RouteData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Routes\EmptyRouteRequest;
use App\Http\Requests\Routes\SetRouteTargetRequest;
use App\Http\Requests\Routes\StoreRouteRequest;
use App\Http\Requests\Routes\UpdateRouteRequest;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RoutesController extends Controller
{
    #[RequiresNodeAccess(ServingNode::Collection)]
    public function index(Request $request, ListRoutesAction $action): JsonResponse
    {
        $consumer = $request->user();
        assert($consumer instanceof Node, description: 'Authenticated peer must be a Node.');

        return response()->json([
            'data' => $action
                ->handle($consumer)
                ->map(static fn (Route $route): array => RouteData::fromModel($route)->toArray())
                ->values()
                ->all(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::RouteOwning)]
    public function store(StoreRouteRequest $request, CreateRouteAction $action): JsonResponse
    {
        $result = $action->execute($request->payload());

        return response()->json(
            [
                'data' => RouteData::fromModel($result['route'])->toArray(),
                'meta' => $this->meta($request),
            ],
            $result['created'] ? 201 : 200,
        );
    }

    #[RequiresNodeAccess(ServingNode::RouteOwning)]
    public function show(Request $request, Route $route, ShowRouteAction $action): JsonResponse
    {
        return response()->json([
            'data' => RouteData::fromModel($action->handle($route))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::RouteOwning)]
    public function update(UpdateRouteRequest $request, Route $route, UpdateRouteAction $action): JsonResponse
    {
        return response()->json([
            'data' => RouteData::fromModel($action->execute($route, $request->payload()))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::RouteOwning)]
    public function setTarget(
        SetRouteTargetRequest $request,
        Route $route,
        SetRouteTargetAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => RouteData::fromModel($action->execute($route, $request->appInstanceId()))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::RouteOwning)]
    public function clearTarget(EmptyRouteRequest $request, Route $route, ClearRouteTargetAction $action): JsonResponse
    {
        return response()->json([
            'data' => RouteData::fromModel($action->execute($route))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::RouteOwning)]
    public function destroy(EmptyRouteRequest $request, Route $route, RemoveRouteAction $action): JsonResponse
    {
        return response()->json([
            'data' => RouteData::fromModel($action->execute($route))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
