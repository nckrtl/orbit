<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\AppInstances\Dependencies\ResolveDependencyInstanceAction;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppInstances\ResolveDependencyInstanceRequest;
use App\Models\Node;
use Illuminate\Http\JsonResponse;

#[RequiresNodeAccess(ServingNode::Collection)]
final class ResolveDependencyInstanceController extends Controller
{
    public function __invoke(ResolveDependencyInstanceRequest $request, ResolveDependencyInstanceAction $action): JsonResponse
    {
        /** @var Node $consumer */
        $consumer = $request->user();

        return response()->json([
            'data' => $action->execute($request->validated('domain'), $consumer)->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
