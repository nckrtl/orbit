<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\AppInstances\ShowAppInstanceQueueAction;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppInstances\AppInstanceQueueRequest;
use App\Models\AppInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AppInstanceQueueController extends Controller
{
    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function show(
        AppInstanceQueueRequest $request,
        AppInstance $instance,
        ShowAppInstanceQueueAction $action,
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
