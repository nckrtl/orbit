<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\T3\CreateT3ProfileAction;
use App\Actions\T3\ReplaceT3ProfileSettingsAction;
use App\Data\T3\T3ProfileData;
use App\Data\T3\T3ProfileSettingsData;
use App\Http\Controllers\Controller;
use App\Http\Requests\T3\ReplaceT3ProfileSettingsRequest;
use App\Http\Requests\T3\StoreT3ProfileRequest;
use App\Models\T3Profile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class T3ProfilesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => T3Profile::query()
                ->orderBy('name')
                ->get()
                ->map(static fn (T3Profile $profile): array => T3ProfileData::fromModel($profile)->toArray())
                ->values()
                ->all(),
            'meta' => $this->meta($request),
        ]);
    }

    public function store(StoreT3ProfileRequest $request, CreateT3ProfileAction $action): JsonResponse
    {
        return response()->json([
            'data' => T3ProfileData::fromModel($action->execute($request->payload()))->toArray(),
            'meta' => $this->meta($request),
        ], 201);
    }

    public function settings(Request $request, T3Profile $profile): JsonResponse
    {
        return response()->json([
            'data' => T3ProfileSettingsData::fromModel($profile)->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    public function replaceSettings(
        ReplaceT3ProfileSettingsRequest $request,
        T3Profile $profile,
        ReplaceT3ProfileSettingsAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => T3ProfileSettingsData::fromModel($action->execute($profile, $request->payload()))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
