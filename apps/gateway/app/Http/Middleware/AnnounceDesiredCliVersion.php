<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Fleet\DesiredFleetState;
use App\Models\Node;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Tells a CLI which CLI release the fleet should run (ADR 0202). When the request names its client version in
 * `X-Orbit-Client-Version` and comes from an active WireGuard peer, the response carries `X-Orbit-Cli-Version`
 * with the desired release. The value comes from the cache alone, so no request waits for Git or GitHub; the
 * header is absent until the desired state is resolved and while the release is unavailable. The state is
 * resolved only for such a request, so no other request depends on the cache store.
 */
final readonly class AnnounceDesiredCliVersion
{
    public const string ClientVersionHeader = 'X-Orbit-Client-Version';

    public const string DesiredVersionHeader = 'X-Orbit-Cli-Version';

    public function __construct(private Container $container) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->headers->has(self::ClientVersionHeader) || ! $request->user() instanceof Node) {
            return $response;
        }

        try {
            $release = $this->container->make(DesiredFleetState::class)->cached()?->cli;
        } catch (Throwable) {
            return $response;
        }

        if ($release?->isAvailable() === true && is_string($release->version)) {
            $response->headers->set(self::DesiredVersionHeader, $release->version);
        }

        return $response;
    }
}
