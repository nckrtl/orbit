<?php

declare(strict_types=1);

namespace App\Http\Mcp;

use App\Http\Middleware\ExpireOutdatedMcpSessions;
use Laravel\Mcp\Enums\ProtocolVersion;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * Answers a `server/discover` probe with only the protocol versions that open a session with `initialize`.
 *
 * A client that probes then falls back to `initialize`, and its session id carries the tool manifest version
 * ({@see ExpireOutdatedMcpSessions}). The 2026-07-28 revision has no session, so a client
 * on it could learn of a new tool list only through a held `subscriptions/listen` stream, which would keep a
 * PHP-FPM worker busy for each connected client. Requests in that revision still work, so a client that
 * connected with it before keeps working.
 */
final class InitializeHandshakeDiscover implements Method
{
    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        return JsonRpcResponse::result($request->id, [
            'supportedVersions' => ProtocolVersion::initializeSupported(),
            'capabilities' => $context->serverCapabilities ?: (object) [],
            'instructions' => $context->instructions,
        ]);
    }
}
