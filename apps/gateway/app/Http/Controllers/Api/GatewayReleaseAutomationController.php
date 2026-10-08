<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\GatewayReleases\ShowGatewayReleaseAutomationAction;
use App\Domain\GatewayReleases\GatewayReleaseAutomation;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The automatic release switch, its pause, and the runner's last tick. */
#[RequiresNodeAccess(ServingNode::Gateway)]
final class GatewayReleaseAutomationController extends Controller
{
    public function show(Request $request, ShowGatewayReleaseAutomationAction $action): JsonResponse
    {
        return $this->respond($request, $action);
    }

    public function enable(Request $request, GatewayReleaseAutomation $automation, ShowGatewayReleaseAutomationAction $action): JsonResponse
    {
        $automation->enable();

        return $this->respond($request, $action);
    }

    public function disable(Request $request, GatewayReleaseAutomation $automation, ShowGatewayReleaseAutomationAction $action): JsonResponse
    {
        $automation->disable();

        return $this->respond($request, $action);
    }

    public function resume(Request $request, GatewayReleaseAutomation $automation, ShowGatewayReleaseAutomationAction $action): JsonResponse
    {
        if ($automation->resume() === null) {
            throw new GatewayReleaseException(
                step: 'resume',
                errorCode: 'gateway.release_not_paused',
                message: 'Automatic releases are not paused.',
            );
        }

        return $this->respond($request, $action);
    }

    private function respond(Request $request, ShowGatewayReleaseAutomationAction $action): JsonResponse
    {
        return response()->json([
            'data' => $action->execute()->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
