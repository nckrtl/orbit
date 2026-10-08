<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Fleet\ConvergeNodeFootprintAction;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fleet\ConvergeNodeFootprintRequest;
use App\Models\Node;
use Illuminate\Http\JsonResponse;

final class NodeFootprintsController extends Controller
{
    #[RequiresNodeAccess(ServingNode::Target)]
    public function converge(ConvergeNodeFootprintRequest $request, Node $node, ConvergeNodeFootprintAction $action): JsonResponse
    {
        return response()->json([
            'data' => $action->execute($node, $request->force())->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
