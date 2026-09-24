<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build;

/**
 * The four listener rules of ADR 0141. Each site source has exactly one.
 */
enum CaddyListenerRule: string
{
    /** Workload and Router sites, custom proxy Routes, tracking hosts, Agentation, and Vite: `0.0.0.0`. */
    case Wildcard = 'wildcard';

    /** Public Ingress sites: `0.0.0.0`, reached on the public address. */
    case Public = 'public';

    /** `gateway.orbit`, `metrics.orbit`, and the service metrics scrape site: the WireGuard address only. */
    case WireGuard = 'wireguard';

    /** `websocket`, `analytics`, ProxyCli, and Herdr observers: the WireGuard address, or `0.0.0.0` beside a wildcard site. */
    case Shared = 'shared';
}
