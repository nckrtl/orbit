<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\UpdateInstanceEnvironmentAction;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Instances\UpdateInstanceEnvironmentRequest;
use App\Models\Instance;
use Illuminate\Http\JsonResponse;

final class InstanceEnvironmentValuesController extends Controller
{
    #[RequiresNodeAccess(ServingNode::EnvironmentInstanceOwning)]
    public function update(
        UpdateInstanceEnvironmentRequest $request,
        UpdateInstanceEnvironmentAction $action,
    ): JsonResponse {
        $instance = $request->route('instance');
        $key = $request->route('key');
        assert($instance instanceof Instance);
        assert(is_string($key));

        return response()->json([
            'data' => $action->execute($instance, $key, $request->value())->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
