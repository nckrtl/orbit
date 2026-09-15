<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\DatabaseConnections\CreateManagedMysqlUserAction;
use App\Data\DatabaseConnections\DatabaseConnectionData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\DatabaseConnections\CreateManagedMysqlUserRequest;
use App\Models\Process;
use Illuminate\Http\JsonResponse;
use SensitiveParameter;

#[RequiresNodeAccess(ServingNode::ProcessOwning)]
final class DatabaseUsersController extends Controller
{
    public function store(
        CreateManagedMysqlUserRequest $request,
        #[SensitiveParameter]
        Process $process,
        CreateManagedMysqlUserAction $action,
    ): JsonResponse {
        $connection = $action->execute($process, $request->payload());
        $payload = [
            'data' => DatabaseConnectionData::fromModel($connection)->toArray(),
            'meta' => $this->meta($request),
        ];

        if ($connection->wasRecentlyCreated) {
            return response()->json($payload, 201);
        }

        return response()->json($payload);
    }

    /** @return array{request_id: string} */
    private function meta(CreateManagedMysqlUserRequest $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
