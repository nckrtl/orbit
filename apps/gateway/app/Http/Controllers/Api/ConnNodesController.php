<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Conn\BindConnNodeProfileAction;
use App\Data\Conn\ConnNodeData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conn\BindConnProfileRequest;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ConnNodesController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return $this->respond($request, $this->caller($request));
    }

    public function bindProfile(BindConnProfileRequest $request, BindConnNodeProfileAction $action): JsonResponse
    {
        $node = $this->caller($request);
        $action->execute($node, $request->profile());

        return $this->respond($request, $node);
    }

    private function caller(Request $request): Node
    {
        $node = $request->user();
        assert($node instanceof Node, description: 'Authenticated peer must be a Node.');

        return $node;
    }

    private function respond(Request $request, Node $node): JsonResponse
    {
        return response()->json([
            'data' => ConnNodeData::fromModel($node)->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
