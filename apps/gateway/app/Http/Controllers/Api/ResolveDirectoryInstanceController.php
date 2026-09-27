<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\AppInstances\Dependencies\ResolveDirectoryInstanceAction;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppInstances\ResolveDirectoryInstanceRequest;
use App\Models\Node;
use Illuminate\Http\JsonResponse;

#[RequiresNodeAccess(ServingNode::Collection)]
final class ResolveDirectoryInstanceController extends Controller
{
    public function __invoke(ResolveDirectoryInstanceRequest $request, ResolveDirectoryInstanceAction $action): JsonResponse
    {

        $consumer = $request->user();
        abort_unless($consumer instanceof Node, 401);

        $directory = $request->validated('directory');

        if (! is_string($directory)) {
            throw new \UnexpectedValueException('A directory is required.');
        }

        return response()->json([
            'data' => $action->execute($directory, $consumer)->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
