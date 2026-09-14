<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\DatabaseConnections\AddDatabaseConnectionAction;
use App\Actions\DatabaseConnections\ListDatabaseConnectionsAction;
use App\Actions\DatabaseConnections\RemoveDatabaseConnectionAction;
use App\Actions\DatabaseConnections\UpdateDatabaseConnectionAction;
use App\Data\DatabaseConnections\DatabaseConnectionData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\DatabaseConnections\StoreDatabaseConnectionRequest;
use App\Http\Requests\DatabaseConnections\UpdateDatabaseConnectionRequest;
use App\Models\DatabaseConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class DatabaseConnectionsController extends Controller
{
    public function index(Request $request, ListDatabaseConnectionsAction $action): JsonResponse
    {
        return response()->json([
            'data' => $action
                ->execute()
                ->map(static fn (DatabaseConnection $connection): array => DatabaseConnectionData::fromModel($connection)->toArray())
                ->values()
                ->all(),
            'meta' => $this->meta($request),
        ]);
    }

    public function store(
        StoreDatabaseConnectionRequest $request,
        AddDatabaseConnectionAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => DatabaseConnectionData::fromModel($action->execute($request->payload()))->toArray(),
            'meta' => $this->meta($request),
        ], 201);
    }

    public function show(Request $request, DatabaseConnection $databaseConnection): JsonResponse
    {
        return response()->json([
            'data' => DatabaseConnectionData::fromModel($databaseConnection)->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    public function update(
        UpdateDatabaseConnectionRequest $request,
        DatabaseConnection $databaseConnection,
        UpdateDatabaseConnectionAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => DatabaseConnectionData::fromModel(
                $action->execute($databaseConnection, $request->payload()),
            )->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    public function destroy(
        Request $request,
        DatabaseConnection $databaseConnection,
        RemoveDatabaseConnectionAction $action,
    ): JsonResponse {
        $data = DatabaseConnectionData::fromModel($databaseConnection)->toArray();
        $action->execute($databaseConnection);

        return response()->json([
            'data' => $data,
            'meta' => $this->meta($request),
        ]);
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
