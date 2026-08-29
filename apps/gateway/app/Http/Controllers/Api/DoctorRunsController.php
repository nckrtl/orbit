<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Doctor\RunDoctorAction;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Doctor\RunDoctorRequest;
use App\Models\Node;
use Illuminate\Http\JsonResponse;

final class DoctorRunsController extends Controller
{
    /** @mago-expect analysis:mixed-assignment The authenticated peer resolver returns a Node. */
    #[RequiresNodeAccess(ServingNode::Collection)]
    public function store(
        RunDoctorRequest $request,
        RunDoctorAction $action,
        NodeAccessAuthorizer $authorizer,
    ): JsonResponse {
        $consumer = $request->user();
        assert($consumer instanceof Node, description: 'Authenticated peer must be a Node.');
        $nodeId = $request->nodeId();

        if ($nodeId !== null) {
            $node = Node::query()->findOrFail($nodeId);

            if (! $authorizer->allows($consumer, $node)) {
                $request->attributes->set('orbit.error_code', 'node_access.required');

                return $this->accessRequired($consumer, $node);
            }
        }

        return response()->json([
            'data' => $action->execute($consumer, $nodeId, $request->families())->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    private function accessRequired(Node $consumer, Node $serving): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'node_access.required',
                'message' => 'Node access is required.',
                'details' => [
                    'consumer_node' => ['id' => $consumer->id, 'name' => $consumer->name],
                    'serving_node' => ['id' => $serving->id, 'name' => $serving->name],
                ],
            ],
        ], 403);
    }
}
