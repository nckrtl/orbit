<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireActiveWireGuardPeer
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $node = self::activePeer($request);

        if (! $node instanceof Node) {
            $request->attributes->set('orbit.error_code', 'peer.identity_unknown');

            return $this->forbidden();
        }

        $request->setUserResolver(static fn (): Node => $node);

        return $next($request);
    }

    /** The active Node whose WireGuard address sent the request, or null. */
    public static function activePeer(Request $request): ?Node
    {
        $remoteAddress = $request->server('REMOTE_ADDR');

        if (! is_string($remoteAddress) || filter_var($remoteAddress, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return Node::query()
            ->where('wireguard_ip', $remoteAddress)
            ->where('status', LifecycleStatus::Active->value)
            ->first();
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'peer.identity_unknown',
                'message' => 'Active WireGuard peer identity required.',
                'details' => [],
            ],
        ], 403);
    }
}
