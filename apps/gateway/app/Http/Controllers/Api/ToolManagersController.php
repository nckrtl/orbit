<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Tools\ListToolManagersAction;
use App\Data\Tools\ToolManagerData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tools\ListToolManagersRequest;
use App\Models\ToolManagerRecord;
use Illuminate\Http\JsonResponse;

#[RequiresNodeAccess(ServingNode::ToolOwning)]
final class ToolManagersController extends Controller
{
    public function index(
        ListToolManagersRequest $request,
        ListToolManagersAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => $action
                ->execute($request->nodeId())
                ->map(
                    static fn (ToolManagerRecord $manager): array => ToolManagerData::fromModel($manager)->toArray(),
                )
                ->all(),
            'meta' => $this->meta($request),
        ]);
    }

    /** @return array{request_id: string} */
    private function meta(ListToolManagersRequest $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
