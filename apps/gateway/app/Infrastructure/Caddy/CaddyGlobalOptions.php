<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy;

final readonly class CaddyGlobalOptions
{
    /**
     * Every Node runs the same global options: no certificate automation unless a site opts in
     * (ADR 0138), `abort` before every other handler so a site's client guard runs first (ADR 0157),
     * and per-host HTTP metrics that service metrics scrapes on Ingress (ADR 0139).
     */
    public static function render(): string
    {
        return <<<'CADDY'
            {
                auto_https disable_certs
                order abort first
                metrics {
                    per_host
                }
            }
            CADDY.PHP_EOL;
    }
}
