<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Gateway\BrowserOriginPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses browser requests to the API and MCP paths from pages outside Orbit before CORS,
 * routing, or WireGuard identity run. Clients that send no browser markers pass unchanged
 * ([ADR 0125](/decisions/0125-limit-browser-api-calls-to-orbit-origins)).
 */
final readonly class GuardBrowserOrigins
{
    public function __construct(private BrowserOriginPolicy $policy) {}

    public function handle(Request $request, Closure $next): Response
    {
        config(['cors.allowed_origins' => []]);

        if (! $request->is('api/*', 'mcp', 'mcp/*')) {
            return $next($request);
        }

        $origin = $request->headers->get('Origin');
        $site = $request->headers->get('Sec-Fetch-Site');

        if ($origin === null) {
            return in_array($site, [null, 'same-origin', 'none'], strict: true)
                ? $next($request)
                : $this->refuse();
        }

        if ($origin === $request->getSchemeAndHttpHost()) {
            return $next($request);
        }

        if (! $this->policy->allows($origin)) {
            return $this->refuse();
        }

        config(['cors.allowed_origins' => [$origin]]);

        return $next($request);
    }

    private function refuse(): Response
    {
        return response()->json([
            'error' => [
                'code' => 'api.origin_refused',
                'message' => 'Browser requests from this origin cannot reach the Gateway API.',
            ],
        ], 403);
    }
}
