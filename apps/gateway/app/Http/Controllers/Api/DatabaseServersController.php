<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\DatabaseServers\CreateDatabaseServerAction;
use App\Actions\DatabaseServers\ListDatabaseServersAction;
use App\Actions\DatabaseServers\RemoveDatabaseServerAction;
use App\Data\DatabaseConnections\DatabaseConnectionData;
use App\Data\DatabaseServers\DatabaseServerData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\DatabaseServers\StoreDatabaseServerRequest;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class DatabaseServersController extends Controller
{
    public function index(Request $request, ListDatabaseServersAction $action): JsonResponse
    {
        return response()->json([
            'data' => $action
                ->execute()
                ->map(static fn (DatabaseServer $server): array => DatabaseServerData::fromModel($server)->toArray())
                ->values()
                ->all(),
            'meta' => $this->meta($request),
        ]);
    }

    public function store(StoreDatabaseServerRequest $request, CreateDatabaseServerAction $action): JsonResponse
    {
        $result = $action->execute($request->payload());

        return response()->json([
            'data' => DatabaseServerData::fromModel($result['server'])->toArray(),
            'meta' => $this->meta($request),
        ], $result['created'] ? 201 : 200);
    }

    public function show(Request $request, DatabaseServer $databaseServer): JsonResponse
    {
        return response()->json([
            'data' => [
                ...DatabaseServerData::fromModel($databaseServer)->toArray(),
                'databases' => $databaseServer->databaseConnections()
                    ->with('server')
                    ->orderBy('slug')
                    ->get()
                    ->map(static fn (DatabaseConnection $connection): array => DatabaseConnectionData::fromModel($connection)->toArray())
                    ->values()
                    ->all(),
            ],
            'meta' => $this->meta($request),
        ]);
    }

    public function destroy(
        Request $request,
        DatabaseServer $databaseServer,
        RemoveDatabaseServerAction $action,
    ): JsonResponse {
        $data = DatabaseServerData::fromModel($databaseServer)->toArray();
        $action->execute($databaseServer);

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
