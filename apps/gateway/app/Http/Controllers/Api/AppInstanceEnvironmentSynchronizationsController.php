<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\AppInstances\SynchronizeAppInstanceEnvironmentAction;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppInstances\SynchronizeAppInstanceEnvironmentRequest;
use App\Models\AppInstance;
use Illuminate\Http\JsonResponse;

final class AppInstanceEnvironmentSynchronizationsController extends Controller
{
    #[RequiresNodeAccess(ServingNode::EnvironmentInstanceOwning)]
    public function store(
        SynchronizeAppInstanceEnvironmentRequest $request,
        SynchronizeAppInstanceEnvironmentAction $action,
    ): JsonResponse {
        $instance = $request->route('instance');
        assert($instance instanceof AppInstance);

        return response()->json([
            'data' => $action->execute($instance)->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
