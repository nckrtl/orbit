<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\T3\ResolveT3PeerAction;
use App\Models\T3Peer;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admits any WireGuard peer to the T3 Code layer, also one that is not a Node, and puts its T3Peer
 * on the request. This layer has no grants yet: every known peer may use every T3 endpoint.
 */
final readonly class RequireT3Peer
{
    public function __construct(private ResolveT3PeerAction $resolve) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $peer = $this->resolve->handle($request->server('REMOTE_ADDR'));

        if (! $peer instanceof T3Peer) {
            $request->attributes->set('orbit.error_code', 'peer.identity_unknown');

            return $this->forbidden();
        }

        $request->attributes->set('orbit.t3_peer', $peer);

        return $next($request);
    }

    public static function peer(Request $request): T3Peer
    {
        $peer = $request->attributes->get('orbit.t3_peer');
        assert($peer instanceof T3Peer, description: 'RequireT3Peer must run first.');

        return $peer;
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'peer.identity_unknown',
                'message' => 'WireGuard peer identity required.',
                'details' => [],
            ],
        ], 403);
    }
}
