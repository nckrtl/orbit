<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\ShowInstanceLogsAction;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Instances\InstanceLogsRequest;
use App\Models\Instance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InstanceLogsController extends Controller
{
    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function show(
        InstanceLogsRequest $request,
        Instance $instance,
        ShowInstanceLogsAction $action,
    ): JsonResponse {
        $lines = $request->lines();

        return response()->json([
            'data' => [
                'id' => $instance->id,
                'name' => $instance->name,
                'lines' => $lines,
                'logs' => $action->execute($instance, $lines),
            ],
            'meta' => $this->meta($request),
        ]);
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
