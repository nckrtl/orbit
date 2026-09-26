<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Logs\LogStream;
use App\Domain\Logs\LogStreamStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The response shapes shared by the Instance and Process live log stream routes. */
trait RespondsWithLogStreams
{
    private function viewer(Request $request): Node
    {
        $peer = $request->user();

        if (! $peer instanceof Node) {
            throw new ResourceOperationException('peer.identity_unknown', 'Active WireGuard peer identity required.', 403);
        }

        return $peer;
    }

    /** @param array{stream: LogStream, auth: string} $opened */
    private function opened(Request $request, array $opened): JsonResponse
    {
        return response()->json([
            'data' => [
                'id' => $opened['stream']->id,
                'channel' => $opened['stream']->channel(),
                'auth' => $opened['auth'],
                'lines' => $opened['stream']->lines,
                'lease_seconds' => LogStreamStore::LeaseSeconds,
                'renew_seconds' => LogStreamStore::RenewSeconds,
            ],
            'meta' => $this->streamMeta($request),
        ], 201);
    }

    private function renewed(Request $request, LogStream $stream): JsonResponse
    {
        return response()->json([
            'data' => ['id' => $stream->id, 'lease_seconds' => LogStreamStore::LeaseSeconds],
            'meta' => $this->streamMeta($request),
        ]);
    }

    private function closed(Request $request, LogStream $stream): JsonResponse
    {
        return response()->json([
            'data' => ['id' => $stream->id, 'closed' => true],
            'meta' => $this->streamMeta($request),
        ]);
    }

    /** @return array{request_id: string} */
    private function streamMeta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
