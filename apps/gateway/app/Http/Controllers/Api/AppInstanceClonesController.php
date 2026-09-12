<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\AppInstances\CloneAppInstanceAction;
use App\Data\AppInstances\AppInstanceData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppInstances\CloneAppInstanceRequest;
use App\Models\AppInstance;
use Illuminate\Http\JsonResponse;

final class AppInstanceClonesController extends Controller
{
    #[RequiresNodeAccess(ServingNode::CandidateClone)]
    public function store(
        CloneAppInstanceRequest $request,
        AppInstance $candidate,
        CloneAppInstanceAction $action,
    ): JsonResponse {
        $result = $action->execute($candidate, $request->payload());
        $request->attributes->set('orbit.app_instance_clone', $result['appInstance']);
        $request->route()?->setParameter('instance', $result['appInstance']);

        return response()->json(
            [
                'data' => AppInstanceData::fromModel($result['appInstance'])->toArray(),
                'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
            ],
            $result['created'] ? 201 : 200,
        );
    }
}
