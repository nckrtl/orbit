<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\CloneInstanceAction;
use App\Data\Instances\InstanceData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Instances\CloneInstanceRequest;
use App\Models\Instance;
use Illuminate\Http\JsonResponse;

final class InstanceClonesController extends Controller
{
    #[RequiresNodeAccess(ServingNode::CandidateClone)]
    public function store(
        CloneInstanceRequest $request,
        Instance $candidate,
        CloneInstanceAction $action,
    ): JsonResponse {
        $result = $action->execute($candidate, $request->payload());
        $request->attributes->set('orbit.instance_clone', $result['instance']);
        $request->route()?->setParameter('instance', $result['instance']);

        return response()->json(
            [
                'data' => InstanceData::fromModel($result['instance'])->toArray(),
                'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
            ],
            $result['created'] ? 201 : 200,
        );
    }
}
