<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Broadcasting\ShowRealtimeConfigAction;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class RealtimeConfigController extends Controller
{
    public function show(Request $request, ShowRealtimeConfigAction $action): JsonResponse
    {
        return response()->json([
            'data' => $action->execute()->toArray(),
            'meta' => [
                'request_id' => $request->attributes->getString('orbit.request_id'),
            ],
        ]);
    }
}
