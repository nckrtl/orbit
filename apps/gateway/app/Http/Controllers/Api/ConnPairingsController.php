<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Conn\IssueConnPairingAction;
use App\Actions\Conn\RevokeConnPairingsAction;
use App\Data\Conn\ConnPairingData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conn\RevokeConnPairingsRequest;
use App\Models\ConnEnvironment;
use App\Models\ConnPairing;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ConnPairingsController extends Controller
{
    public function index(Request $request, ConnEnvironment $environment): JsonResponse
    {
        return response()->json([
            'data' => ConnPairing::query()
                ->where('conn_environment_id', $environment->id)
                ->with(['environment', 'node'])
                ->orderByDesc('id')
                ->get()
                ->map(static fn (ConnPairing $pairing): array => ConnPairingData::fromModel($pairing)->toArray())
                ->values()
                ->all(),
            'meta' => $this->meta($request),
        ]);
    }

    public function store(Request $request, ConnEnvironment $environment, IssueConnPairingAction $action): JsonResponse
    {
        $node = $request->user();
        assert($node instanceof Node, description: 'Authenticated peer must be a Node.');

        return response()->json([
            'data' => $action->execute($node, $environment)->toArray(),
            'meta' => $this->meta($request),
        ], 201);
    }

    public function revoke(RevokeConnPairingsRequest $request, ConnEnvironment $environment, RevokeConnPairingsAction $action): JsonResponse
    {
        return response()->json([
            'data' => $action->execute($request->node(), $environment)->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
