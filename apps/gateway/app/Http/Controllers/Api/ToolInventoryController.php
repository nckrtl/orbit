<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Tools\ScanToolInventoryAction;
use App\Data\Tools\ToolInventoryData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tools\ScanToolInventoryRequest;
use Illuminate\Http\JsonResponse;

#[RequiresNodeAccess(ServingNode::ToolOwning)]
final class ToolInventoryController extends Controller
{
    public function scan(
        ScanToolInventoryRequest $request,
        ScanToolInventoryAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => ToolInventoryData::fromReport($action->execute($request->nodeId()))->toArray(),
            'meta' => [
                'request_id' => $request->attributes->getString('orbit.request_id'),
            ],
        ]);
    }
}
