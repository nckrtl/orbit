<?php

declare(strict_types=1);

/*
 * Browser access to the Gateway is decided per request by GuardBrowserOrigins, which fills
 * `allowed_origins` with the one origin it admits (ADR 0126). Nothing is allowed by default.
 */
return [
    'paths' => ['api/*', 'mcp', 'mcp/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['X-Orbit-Request-Id'],
    'max_age' => 0,
    'supports_credentials' => false,
];
