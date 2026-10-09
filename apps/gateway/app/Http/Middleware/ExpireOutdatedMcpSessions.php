<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Mcp\ToolManifest;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ties each MCP session to the tool list it was opened under.
 *
 * The session id starts with the tool list version ({@see ToolManifest::version()}): the names and input
 * schemas of the offered tools. When a release or an extension switch changes them, a request with an older
 * session id receives HTTP 404, the MCP answer for an ended session, and does not run. A release that only
 * rewords descriptions keeps the sessions. The Gateway stores no session state.
 */
final class ExpireOutdatedMcpSessions
{
    public const string Header = 'Mcp-Session-Id';

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $version = ToolManifest::default()->version();
        $session = $request->headers->get(self::Header);

        if (is_string($session) && ! str_starts_with($session, $version.'.')) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32001, 'message' => 'Session not found'],
            ], 404);
        }

        $response = $next($request);

        if ($session === null && $response->isSuccessful() && $this->initializes($request)) {
            $response->headers->set(self::Header, $version.'.'.bin2hex(random_bytes(16)));
        }

        return $response;
    }

    private function initializes(Request $request): bool
    {
        $content = $request->getContent();

        if (! str_contains($content, '"initialize"')) {
            return false;
        }

        $message = json_decode($content, true);

        return is_array($message) && ($message['method'] ?? null) === 'initialize';
    }
}
