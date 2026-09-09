<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\AppInstances\ImportAppInstanceEnvironmentAction;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppInstances\ImportAppInstanceEnvironmentRequest;
use App\Models\AppInstance;
use Illuminate\Http\JsonResponse;

final class AppInstanceEnvironmentImportsController extends Controller
{
    #[RequiresNodeAccess(ServingNode::EnvironmentInstanceOwning)]
    public function store(
        ImportAppInstanceEnvironmentRequest $request,
        ImportAppInstanceEnvironmentAction $action,
    ): JsonResponse {
        $instance = $request->route('instance');
        assert($instance instanceof AppInstance);

        return response()->json([
            'data' => $action->execute($instance, $request->shouldReplace())->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
