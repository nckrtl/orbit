<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\DatabaseConnections\AddDatabaseConnectionAction;
use App\Actions\DatabaseConnections\DescribeDatabaseTableAction;
use App\Actions\DatabaseConnections\ListDatabaseConnectionsAction;
use App\Actions\DatabaseConnections\ListDatabaseTablesAction;
use App\Actions\DatabaseConnections\ListDatabaseUsersAction;
use App\Actions\DatabaseConnections\QueryDatabaseConnectionAction;
use App\Actions\DatabaseConnections\RemoveDatabaseConnectionAction;
use App\Actions\DatabaseConnections\ShowDatabaseSchemaAction;
use App\Actions\DatabaseConnections\UpdateDatabaseConnectionAction;
use App\Data\DatabaseConnections\DatabaseConnectionData;
use App\Data\DatabaseConnections\DatabaseUserData;
use App\Domain\DatabaseConnections\DatabaseSchemaTable;
use App\Domain\DatabaseConnections\DatabaseTableColumn;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\DatabaseConnections\DescribeDatabaseTableRequest;
use App\Http\Requests\DatabaseConnections\QueryDatabaseConnectionRequest;
use App\Http\Requests\DatabaseConnections\StoreDatabaseConnectionRequest;
use App\Http\Requests\DatabaseConnections\UpdateDatabaseConnectionRequest;
use App\Models\DatabaseConnection;
use App\Models\DatabaseUser;
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
            'data' => [
                ...DatabaseConnectionData::fromModel($databaseConnection)->toArray(),
                'users_count' => $databaseConnection->users()->count(),
            ],
            'meta' => $this->meta($request),
        ]);
    }

    public function users(
        Request $request,
        DatabaseConnection $databaseConnection,
        ListDatabaseUsersAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => $action->execute($databaseConnection)
                ->map(static fn (DatabaseUser $user): array => DatabaseUserData::fromModel($user)->toArray())
                ->values()
                ->all(),
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

    public function query(
        QueryDatabaseConnectionRequest $request,
        DatabaseConnection $databaseConnection,
        QueryDatabaseConnectionAction $action,
    ): JsonResponse {
        $result = $action->execute($databaseConnection, $request->sql(), $request->write());

        return response()->json([
            'data' => [
                'slug' => $databaseConnection->slug,
                'driver' => $databaseConnection->driver->value,
                'write' => $request->write(),
                'columns' => $result->columns,
                'rows' => $result->rows,
                'row_count' => $result->rowCount,
                'truncated' => $result->truncated,
            ],
            'meta' => $this->meta($request),
        ]);
    }

    public function tables(
        Request $request,
        DatabaseConnection $databaseConnection,
        ListDatabaseTablesAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => [
                'slug' => $databaseConnection->slug,
                'driver' => $databaseConnection->driver->value,
                'tables' => $action->execute($databaseConnection),
            ],
            'meta' => $this->meta($request),
        ]);
    }

    public function schema(
        Request $request,
        DatabaseConnection $databaseConnection,
        ShowDatabaseSchemaAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => [
                'slug' => $databaseConnection->slug,
                'driver' => $databaseConnection->driver->value,
                'tables' => array_map(
                    static fn (DatabaseSchemaTable $table): array => $table->toArray(),
                    $action->execute($databaseConnection),
                ),
            ],
            'meta' => $this->meta($request),
        ]);
    }

    public function describe(
        DescribeDatabaseTableRequest $request,
        DatabaseConnection $databaseConnection,
        DescribeDatabaseTableAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => [
                'slug' => $databaseConnection->slug,
                'driver' => $databaseConnection->driver->value,
                'table' => $request->table(),
                'columns' => array_map(
                    static fn (DatabaseTableColumn $column): array => $column->toArray(),
                    $action->execute($databaseConnection, $request->table()),
                ),
            ],
            'meta' => $this->meta($request),
        ]);
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
