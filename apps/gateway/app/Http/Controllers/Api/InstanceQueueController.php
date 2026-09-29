<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\ShowInstanceQueueAction;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Instances\InstanceQueueRequest;
use App\Models\Instance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InstanceQueueController extends Controller
{
    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function show(
        InstanceQueueRequest $request,
        Instance $instance,
        ShowInstanceQueueAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => $action->execute($instance, $request->state(), $request->limit()),
            'meta' => $this->meta($request),
        ]);
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
