<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\T3\IssueT3PairingAction;
use App\Actions\T3\RevokeT3PairingsAction;
use App\Data\T3\T3PairingData;
use App\Http\Controllers\Controller;
use App\Http\Requests\T3\RevokeT3PairingsRequest;
use App\Models\Node;
use App\Models\T3Environment;
use App\Models\T3Pairing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class T3PairingsController extends Controller
{
    public function index(Request $request, T3Environment $environment): JsonResponse
    {
        return response()->json([
            'data' => T3Pairing::query()
                ->where('t3_environment_id', $environment->id)
                ->with(['environment', 'node'])
                ->orderByDesc('id')
                ->get()
                ->map(static fn (T3Pairing $pairing): array => T3PairingData::fromModel($pairing)->toArray())
                ->values()
                ->all(),
            'meta' => $this->meta($request),
        ]);
    }

    public function store(Request $request, T3Environment $environment, IssueT3PairingAction $action): JsonResponse
    {
        $node = $request->user();
        assert($node instanceof Node, description: 'Authenticated peer must be a Node.');

        return response()->json([
            'data' => $action->execute($node, $environment)->toArray(),
            'meta' => $this->meta($request),
        ], 201);
    }

    public function revoke(RevokeT3PairingsRequest $request, T3Environment $environment, RevokeT3PairingsAction $action): JsonResponse
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
