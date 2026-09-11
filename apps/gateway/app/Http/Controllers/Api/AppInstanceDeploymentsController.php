<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\AppInstances\DeployAppInstanceAction;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppInstances\DeployAppInstanceRequest;
use App\Http\Streaming\DeploymentStreamResponse;
use App\Models\AppInstance;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AppInstanceDeploymentsController extends Controller
{
    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function store(
        DeployAppInstanceRequest $request,
        AppInstance $instance,
        DeployAppInstanceAction $action,
        DeploymentStreamResponse $stream,
    ): StreamedResponse {
        return $stream->make($request, static fn ($deploymentRequest) => $action->execute(
            $instance,
            $deploymentRequest,
        ));
    }
}
