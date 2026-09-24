<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build;

/**
 * One site block a site source contributes to a Node's Caddyfile.
 */
final readonly class CaddySite
{
    /**
     * @param  non-empty-string  $source  The site source, such as `app-dev` or `websocket`.
     * @param  non-empty-string  $name  The site within its source, such as a Route scope or a hostname.
     * @param  list<string>  $hosts  The site addresses without scheme or port.
     * @param  string  $body  The rendered site block. A shared site binds `$bindPlaceholder`.
     * @param  list<string>  $unixSockets  Unix socket listeners, which keep their own addresses.
     */
    public function __construct(
        public string $source,
        public string $name,
        public CaddyListenerRule $listener,
        public array $hosts,
        public int $port,
        public string $body,
        public ?string $bindPlaceholder = null,
        public array $unixSockets = [],
    ) {}

    public function describe(): string
    {
        return "{$this->source} site {$this->name}";
    }
}
