<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\TransferInstanceAction;
use App\Data\Instances\InstanceData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Instances\TransferInstanceRequest;
use App\Models\Instance;
use Illuminate\Http\JsonResponse;

final class InstanceTransfersController extends Controller
{
    #[RequiresNodeAccess(ServingNode::InstanceTransfer)]
    public function store(
        TransferInstanceRequest $request,
        Instance $instance,
        TransferInstanceAction $action,
    ): JsonResponse {
        $result = $action->execute($instance, $request->payload());

        return response()->json(
            [
                'data' => InstanceData::fromModel($result['instance'])->toArray(),
                'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
            ],
            $result['created'] ? 201 : 200,
        );
    }
}
