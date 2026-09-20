<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Analytics\DisableInstanceAnalyticsAction;
use App\Actions\Analytics\EnableInstanceAnalyticsAction;
use App\Actions\Analytics\ShowInstanceAnalyticsAction;
use App\Actions\Analytics\ShowInstanceAnalyticsStatsAction;
use App\Data\Analytics\InstanceAnalyticsData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\EnableInstanceAnalyticsRequest;
use App\Models\AppInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresNodeAccess(ServingNode::InstanceOwning)]
final class InstanceAnalyticsController extends Controller
{
    public function show(Request $request, AppInstance $instance, ShowInstanceAnalyticsAction $action): JsonResponse
    {
        return $this->respond($request, $action->execute($instance));
    }

    public function enable(
        EnableInstanceAnalyticsRequest $request,
        AppInstance $instance,
        EnableInstanceAnalyticsAction $action,
    ): JsonResponse {
        return $this->respond($request, $action->execute($instance, $request->hosts()));
    }

    public function disable(Request $request, AppInstance $instance, DisableInstanceAnalyticsAction $action): JsonResponse
    {
        return $this->respond($request, $action->execute($instance));
    }

    public function stats(Request $request, AppInstance $instance, ShowInstanceAnalyticsStatsAction $action): JsonResponse
    {
        return response()->json([
            'data' => $action->execute($instance),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    private function respond(Request $request, InstanceAnalyticsData $data): JsonResponse
    {
        return response()->json([
            'data' => $data->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
