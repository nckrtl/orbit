<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Herdr\AdoptHerdrSessionAction;
use App\Actions\Herdr\EnsureHerdrSessionAction;
use App\Actions\Herdr\IssueObservationGrantAction;
use App\Actions\Herdr\ListHerdrSessionsAction;
use App\Actions\Herdr\RemoveHerdrSessionAction;
use App\Actions\Herdr\RestartHerdrSessionAction;
use App\Data\Herdr\HerdrSessionData;
use App\Data\Herdr\ObservationGrantData;
use App\Domain\Herdr\HerdrSessionHealth;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Herdr\ListHerdrSessionsRequest;
use App\Http\Requests\Herdr\RemoveHerdrSessionRequest;
use App\Http\Requests\Herdr\RestartHerdrSessionRequest;
use App\Http\Requests\Herdr\StoreHerdrSessionRequest;
use App\Http\Requests\Herdr\StoreObservationGrantRequest;
use App\Models\HerdrSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresNodeAccess(ServingNode::HerdrSessionOwning)]
final class HerdrSessionsController extends Controller
{
    public function index(
        ListHerdrSessionsRequest $request,
        ListHerdrSessionsAction $action,
        HerdrSessionHealth $health,
    ): JsonResponse {
        return response()->json([
            'data' => $action->execute($request->nodeId())
                ->map(fn (HerdrSession $session): array => HerdrSessionData::fromModel(
                    $session,
                    $health->inspect($session),
                )->toArray())
                ->values()
                ->all(),
            'meta' => $this->meta($request),
        ]);
    }

    public function store(
        StoreHerdrSessionRequest $request,
        EnsureHerdrSessionAction $action,
        HerdrSessionHealth $health,
    ): JsonResponse {
        $result = $action->execute($request->payload());

        return response()->json(
            [
                'data' => HerdrSessionData::fromModel(
                    $result['session'],
                    $health->inspect($result['session']),
                )->toArray(),
                'meta' => $this->meta($request),
            ],
            $result['created'] ? 201 : 200,
        );
    }

    public function adopt(
        StoreHerdrSessionRequest $request,
        AdoptHerdrSessionAction $action,
        HerdrSessionHealth $health,
    ): JsonResponse {
        $result = $action->execute($request->payload());

        return response()->json(
            [
                'data' => HerdrSessionData::fromModel(
                    $result['session'],
                    $health->inspect($result['session']),
                )->toArray(),
                'meta' => $this->meta($request),
            ],
            $result['created'] ? 201 : 200,
        );
    }

    public function show(
        Request $request,
        HerdrSession $session,
        HerdrSessionHealth $health,
    ): JsonResponse {
        $session->loadMissing(['node', 'process']);

        return response()->json([
            'data' => HerdrSessionData::fromModel($session, $health->inspect($session))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    public function restart(
        RestartHerdrSessionRequest $request,
        HerdrSession $session,
        RestartHerdrSessionAction $action,
        HerdrSessionHealth $health,
    ): JsonResponse {
        $session = $action->execute($session, $request->handoff());

        return response()->json([
            'data' => HerdrSessionData::fromModel($session, $health->inspect($session))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    public function destroy(
        RemoveHerdrSessionRequest $request,
        HerdrSession $session,
        RemoveHerdrSessionAction $action,
        HerdrSessionHealth $health,
    ): JsonResponse {
        $session->loadMissing(['node', 'process']);
        $data = HerdrSessionData::fromModel($session, $health->inspect($session))->toArray();
        $action->execute($session, $request->acceptTermination());

        return response()->json([
            'data' => $data,
            'meta' => $this->meta($request),
        ]);
    }

    public function storeGrant(
        StoreObservationGrantRequest $request,
        HerdrSession $session,
        IssueObservationGrantAction $action,
    ): JsonResponse {
        $grant = $action->execute($session, $request->payload());

        return response()->json([
            'data' => ObservationGrantData::fromGrant($grant)->toArray(),
            'meta' => $this->meta($request),
        ], 201);
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
