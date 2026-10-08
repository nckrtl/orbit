<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\GatewayReleases\ShowGatewayReleaseAction;
use App\Actions\GatewayReleases\StartGatewayReleaseDeployAction;
use App\Actions\GatewayReleases\StartGatewayReleaseRollbackAction;
use App\Data\GatewayReleases\GatewayReleaseData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\GatewayReleases\DeployGatewayReleaseRequest;
use App\Http\Requests\GatewayReleases\RollbackGatewayReleaseRequest;
use App\Models\GatewayRelease;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gateway release records, and manual deploys and rollbacks. A deploy or rollback answers 202 with
 * the queued record; the release runs in its own systemd unit and the caller follows the record.
 */
#[RequiresNodeAccess(ServingNode::Gateway)]
final class GatewayReleasesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $releases = GatewayRelease::query()->latest('id')->limit(50)->get();

        return $this->respond($request, $releases->map(static fn (GatewayRelease $release): array => GatewayReleaseData::fromModel($release)->toArray())->values()->all());
    }

    public function show(Request $request, string $release, ShowGatewayReleaseAction $action): JsonResponse
    {
        return $this->respond($request, GatewayReleaseData::fromModel($action->find($release))->toArray());
    }

    public function store(DeployGatewayReleaseRequest $request, StartGatewayReleaseDeployAction $action): JsonResponse
    {
        return $this->respond($request, GatewayReleaseData::fromModel($action->execute($request->commit()))->toArray(), 202);
    }

    public function rollback(RollbackGatewayReleaseRequest $request, string $release, StartGatewayReleaseRollbackAction $action): JsonResponse
    {
        return $this->respond($request, GatewayReleaseData::fromModel($action->execute($release, $request->force()))->toArray(), 202);
    }

    /** @param array<int|string, mixed> $data */
    private function respond(Request $request, array $data, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ], $status);
    }
}
