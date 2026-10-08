<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Fleet\ResumeFleetRolloutAction;
use App\Actions\Fleet\ShowFleetRolloutAction;
use App\Data\Fleet\FleetRolloutStatusData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fleet\ResumeFleetRolloutRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class FleetRolloutsController extends Controller
{
    public function show(Request $request, ShowFleetRolloutAction $action): JsonResponse
    {
        return $this->payload($request, $action->execute());
    }

    public function resume(ResumeFleetRolloutRequest $request, ResumeFleetRolloutAction $action): JsonResponse
    {
        return $this->payload($request, $action->execute($request->skip()));
    }

    private function payload(Request $request, FleetRolloutStatusData $data): JsonResponse
    {
        return response()->json([
            'data' => $data->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
