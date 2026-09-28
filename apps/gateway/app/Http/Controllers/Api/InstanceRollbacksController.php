<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\RollbackInstanceAction;
use App\Http\Authorization\CallerName;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Instances\RollbackInstanceRequest;
use App\Http\Streaming\DeploymentStreamResponse;
use App\Models\Instance;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class InstanceRollbacksController extends Controller
{
    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function store(
        RollbackInstanceRequest $request,
        Instance $instance,
        RollbackInstanceAction $action,
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
