<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\DatabaseConnections\AttachDatabaseConnectionAction;
use App\Actions\DatabaseConnections\DetachDatabaseConnectionAction;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\DatabaseConnections\AttachDatabaseConnectionRequest;
use App\Http\Requests\DatabaseConnections\DetachDatabaseConnectionRequest;
use App\Models\AppInstance;
use App\Models\DatabaseConnection;
use Illuminate\Http\JsonResponse;

#[RequiresNodeAccess(ServingNode::EnvironmentInstanceOwning)]
final class DatabaseConnectionAttachmentsController extends Controller
{
    public function store(
        AttachDatabaseConnectionRequest $request,
        AttachDatabaseConnectionAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => $action->execute(
                $this->instance($request),
                $this->connection($request),
                $request->prefix(),
            )->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    public function destroy(
        DetachDatabaseConnectionRequest $request,
        DetachDatabaseConnectionAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => $action->execute(
                $this->instance($request),
                $this->connection($request),
                $request->prefix(),
            )->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    private function instance(AttachDatabaseConnectionRequest|DetachDatabaseConnectionRequest $request): AppInstance
    {
        $instance = $request->route('instance');
        assert($instance instanceof AppInstance);

        return $instance;
    }

    private function connection(AttachDatabaseConnectionRequest|DetachDatabaseConnectionRequest $request): DatabaseConnection
    {
        $connection = $request->route('database_connection');

        if ($connection instanceof DatabaseConnection) {
            return $connection;
        }

        assert(is_string($connection) && $connection !== '');

        return DatabaseConnection::query()->where('slug', $connection)->firstOrFail();
    }
}
