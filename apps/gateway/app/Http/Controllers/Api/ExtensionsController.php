<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Tasks\TaskBroadcasts;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class ExtensionsController extends Controller
{
    public function index(Request $request, ExtensionStore $extensions): JsonResponse
    {
        return response()->json(['data' => $extensions->all(), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    public function enable(Request $request, string $extension, ExtensionStore $extensions, TaskBroadcasts $broadcasts): JsonResponse
    {
        $this->validateExtension($request, $extension);
        $extensions->set($extension, true);
        if ($extension === 'tasks') {
            $broadcasts->extensionChanged(true);
        }

        return response()->json(['data' => ['name' => $extension, 'enabled' => true], 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    public function disable(Request $request, string $extension, ExtensionStore $extensions, TaskBroadcasts $broadcasts): JsonResponse
    {
        $this->validateExtension($request, $extension);
        $extensions->set($extension, false);
        if ($extension === 'tasks') {
            $broadcasts->extensionChanged(false);
        }

        return response()->json(['data' => ['name' => $extension, 'enabled' => false], 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    private function validateExtension(Request $request, string $extension): void
    {
        validator(['extension' => $extension], ['extension' => ['required', Rule::in(ExtensionStore::Extensions)]])->validate();
    }
}
