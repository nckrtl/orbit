<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\AppInstances\TransferAppInstanceAction;
use App\Data\AppInstances\AppInstanceData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppInstances\TransferAppInstanceRequest;
use App\Models\AppInstance;
use Illuminate\Http\JsonResponse;

final class AppInstanceTransfersController extends Controller
{
    #[RequiresNodeAccess(ServingNode::InstanceTransfer)]
    public function store(
        TransferAppInstanceRequest $request,
        AppInstance $instance,
        TransferAppInstanceAction $action,
    ): JsonResponse {
        $result = $action->execute($instance, $request->payload());

        return response()->json(
            [
                'data' => AppInstanceData::fromModel($result['appInstance'])->toArray(),
                'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
            ],
            $result['created'] ? 201 : 200,
        );
    }
}
