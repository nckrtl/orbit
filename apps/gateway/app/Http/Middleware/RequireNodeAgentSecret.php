<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires the Node agent's secret on every agent endpoint, after RequireActiveWireGuardPeer has named
 * the Node (ADR 0155). Every Unix user on a Node shares its WireGuard address; only the agent can read
 * the secret. A Node that the Gateway last converged with an agent release that sends no secret stays
 * exempt until a converge installs one that does.
 */
final class RequireNodeAgentSecret
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $node = $request->user();

        if (! $node instanceof Node) {
            throw new ResourceOperationException('peer.identity_unknown', 'Active WireGuard peer identity required.', 403);
        }

        $this->authenticate($node, $request->bearerToken());

        return $next($request);
    }

    private function authenticate(Node $node, ?string $secret): void
    {
        $stored = $node->agent_secret_hash;
        $hasStoredSecret = is_string($stored) && $stored !== '';

        if (! $hasStoredSecret && $node->agent_secret_exempt) {
            return;
        }

        if (! is_string($secret) || $secret === '') {
            throw new ResourceOperationException('agent.secret_required', 'The Node agent secret is required.', 401);
        }

        if (! is_string($stored) || $stored === '' || ! hash_equals($stored, hash('sha256', $secret))) {
            throw new ResourceOperationException('agent.secret_invalid', 'The Node agent secret is not valid.', 403);
        }
    }
}
