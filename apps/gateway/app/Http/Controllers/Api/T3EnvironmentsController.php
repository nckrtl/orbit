<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\T3\RegisterT3EnvironmentAction;
use App\Data\T3\T3EnvironmentData;
use App\Http\Controllers\Controller;
use App\Http\Requests\T3\RegisterT3EnvironmentRequest;
use App\Models\Node;
use App\Models\T3Environment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class T3EnvironmentsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => T3Environment::query()
                ->with('node')
                ->orderBy('label')
                ->orderBy('id')
                ->get()
                ->map(static fn (T3Environment $environment): array => T3EnvironmentData::fromModel($environment)->toArray())
                ->values()
                ->all(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    public function store(RegisterT3EnvironmentRequest $request, RegisterT3EnvironmentAction $action): JsonResponse
    {
        $node = $request->user();
        assert($node instanceof Node, description: 'Authenticated peer must be a Node.');

        return response()->json([
            'data' => T3EnvironmentData::fromModel($action->execute($node, $request->payload()))->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
