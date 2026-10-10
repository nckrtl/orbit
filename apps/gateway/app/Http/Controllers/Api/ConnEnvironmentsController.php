<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Conn\RegisterConnEnvironmentAction;
use App\Data\Conn\ConnEnvironmentData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Conn\RegisterConnEnvironmentRequest;
use App\Models\ConnEnvironment;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ConnEnvironmentsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ConnEnvironment::query()
                ->with('node')
                ->orderBy('label')
                ->orderBy('id')
                ->get()
                ->map(static fn (ConnEnvironment $environment): array => ConnEnvironmentData::fromModel($environment)->toArray())
                ->values()
                ->all(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    public function store(RegisterConnEnvironmentRequest $request, RegisterConnEnvironmentAction $action): JsonResponse
    {
        $node = $request->user();
        assert($node instanceof Node, description: 'Authenticated peer must be a Node.');

        return response()->json([
            'data' => ConnEnvironmentData::fromModel($action->execute($node, $request->payload()))->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
