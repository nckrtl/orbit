<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\ImportInstanceEnvironmentAction;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Instances\ImportInstanceEnvironmentRequest;
use App\Models\Instance;
use Illuminate\Http\JsonResponse;

final class InstanceEnvironmentImportsController extends Controller
{
    #[RequiresNodeAccess(ServingNode::EnvironmentInstanceOwning)]
    public function store(
        ImportInstanceEnvironmentRequest $request,
        ImportInstanceEnvironmentAction $action,
    ): JsonResponse {
        $instance = $request->route('instance');
        assert($instance instanceof Instance);

        return response()->json([
            'data' => $action->execute($instance, $request->shouldReplace())->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
