<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\ListInstanceReleasesAction;
use App\Data\Instances\DeploymentReleaseStateData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Models\Instance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InstanceReleasesController extends Controller
{
    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function index(
        Request $request,
        Instance $instance,
        ListInstanceReleasesAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => DeploymentReleaseStateData::fromDomain($action->execute($instance))->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
