<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\T3\BindT3PeerProfileAction;
use App\Data\T3\T3PeerData;
use App\Data\T3\T3ProfileData;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireT3Peer;
use App\Http\Requests\T3\BindT3ProfileRequest;
use App\Models\T3Peer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class T3PeersController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return $this->respond($request, RequireT3Peer::peer($request));
    }

    public function bindProfile(BindT3ProfileRequest $request, BindT3PeerProfileAction $action): JsonResponse
    {
        return $this->respond($request, $action->execute(RequireT3Peer::peer($request), $request->profile()));
    }

    private function respond(Request $request, T3Peer $peer): JsonResponse
    {
        $peer->loadMissing('profile');

        return response()->json([
            'data' => [
                'peer' => T3PeerData::fromModel($peer)->toArray(),
                'profile' => $peer->profile === null ? null : T3ProfileData::fromModel($peer->profile)->toArray(),
            ],
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
