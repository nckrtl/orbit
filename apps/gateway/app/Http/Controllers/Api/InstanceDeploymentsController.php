<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\DeployInstanceAction;
use App\Actions\Instances\ListInstanceDeploymentsAction;
use App\Data\Instances\InstanceDeploymentData;
use App\Http\Authorization\CallerName;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Instances\DeployInstanceRequest;
use App\Http\Streaming\DeploymentStreamResponse;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class InstanceDeploymentsController extends Controller
{
    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function index(
        Request $request,
        Instance $instance,
        ListInstanceDeploymentsAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => $action->execute($instance)
                ->map(static fn (InstanceDeployment $deployment): array => InstanceDeploymentData::fromModel($deployment)->toArray())
                ->values()
                ->all(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::DeploymentOwning)]
    public function show(Request $request, InstanceDeployment $deployment): JsonResponse
    {
        return response()->json([
            'data' => [
                ...InstanceDeploymentData::fromModel($deployment)->toArray(),
                'events' => $deployment->events ?? [],
            ],
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function store(
        DeployInstanceRequest $request,
        Instance $instance,
        DeployInstanceAction $action,
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
