<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\T3\BindT3NodeProfileAction;
use App\Data\T3\T3NodeData;
use App\Http\Controllers\Controller;
use App\Http\Requests\T3\BindT3ProfileRequest;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class T3NodesController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return $this->respond($request, $this->caller($request));
    }

    public function bindProfile(BindT3ProfileRequest $request, BindT3NodeProfileAction $action): JsonResponse
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
            'data' => T3NodeData::fromModel($node)->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
