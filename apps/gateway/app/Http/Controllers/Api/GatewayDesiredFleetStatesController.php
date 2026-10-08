<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Gateway\ShowDesiredFleetStateAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GatewayDesiredFleetStatesController extends Controller
{
    public function show(Request $request, ShowDesiredFleetStateAction $action): JsonResponse
    {
        return response()->json([
            'data' => $action->handle()->toArray(),
            'meta' => [
                'request_id' => $request->attributes->getString('orbit.request_id'),
            ],
        ]);
    }
}
