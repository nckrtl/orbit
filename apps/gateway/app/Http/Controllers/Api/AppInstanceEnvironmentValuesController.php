<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\AppInstances\UpdateAppInstanceEnvironmentAction;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppInstances\UpdateAppInstanceEnvironmentRequest;
use App\Models\AppInstance;
use Illuminate\Http\JsonResponse;

final class AppInstanceEnvironmentValuesController extends Controller
{
    #[RequiresNodeAccess(ServingNode::EnvironmentInstanceOwning)]
    public function update(
        UpdateAppInstanceEnvironmentRequest $request,
        UpdateAppInstanceEnvironmentAction $action,
    ): JsonResponse {
        $instance = $request->route('instance');
        $key = $request->route('key');
        assert($instance instanceof AppInstance);
        assert(is_string($key));

        return response()->json([
            'data' => $action->execute($instance, $key, $request->value())->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
