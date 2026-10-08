<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\SynchronizeInstanceEnvironmentAction;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Instances\SynchronizeInstanceEnvironmentRequest;
use App\Models\Instance;
use Illuminate\Http\JsonResponse;

final class InstanceEnvironmentSynchronizationsController extends Controller
{
    #[RequiresNodeAccess(ServingNode::EnvironmentInstanceOwning)]
    public function store(
        SynchronizeInstanceEnvironmentRequest $request,
        SynchronizeInstanceEnvironmentAction $action,
    ): JsonResponse {
        $instance = $request->route('instance');
        assert($instance instanceof Instance);

        $result = $action->execute($instance);

        // Activity recording reads the base request, not this Form Request copy.
        if ($result->testing !== null) {
            request()->attributes->set('orbit.environment_testing', $result->testing->toArray());
        }

        return response()->json([
            'data' => $result->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
