<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\AppInstances\RollbackAppInstanceAction;
use App\Http\Authorization\CallerName;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppInstances\RollbackAppInstanceRequest;
use App\Http\Streaming\DeploymentStreamResponse;
use App\Models\AppInstance;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AppInstanceRollbacksController extends Controller
{
    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function store(
        RollbackAppInstanceRequest $request,
        AppInstance $instance,
        RollbackAppInstanceAction $action,
        DeploymentStreamResponse $stream,
    ): StreamedResponse {
        $release = $request->release();
        $triggeredBy = CallerName::fromRequest($request);

        return $stream->make($request, $instance, $triggeredBy, static fn ($deploymentRequest) => $action->execute(
            $instance,
            $release,
            $deploymentRequest,
        ));
    }
}
