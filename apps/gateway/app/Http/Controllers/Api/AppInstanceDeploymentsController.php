<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\AppInstances\DeployAppInstanceAction;
use App\Actions\AppInstances\ListAppInstanceDeploymentsAction;
use App\Data\AppInstances\AppInstanceDeploymentData;
use App\Http\Authorization\CallerName;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppInstances\DeployAppInstanceRequest;
use App\Http\Streaming\DeploymentStreamResponse;
use App\Models\AppInstance;
use App\Models\AppInstanceDeployment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AppInstanceDeploymentsController extends Controller
{
    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function index(
        Request $request,
        AppInstance $instance,
        ListAppInstanceDeploymentsAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => $action->execute($instance)
                ->map(static fn (AppInstanceDeployment $deployment): array => AppInstanceDeploymentData::fromModel($deployment)->toArray())
                ->values()
                ->all(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::DeploymentOwning)]
    public function show(Request $request, AppInstanceDeployment $deployment): JsonResponse
    {
        return response()->json([
            'data' => [
                ...AppInstanceDeploymentData::fromModel($deployment)->toArray(),
                'events' => $deployment->events ?? [],
            ],
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function store(
        DeployAppInstanceRequest $request,
        AppInstance $instance,
        DeployAppInstanceAction $action,
        DeploymentStreamResponse $stream,
    ): StreamedResponse {
        $triggeredBy = CallerName::fromRequest($request);

        return $stream->make($request, $instance, $triggeredBy, static fn ($deploymentRequest) => $action->execute(
            $instance,
            $deploymentRequest,
        ));
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
