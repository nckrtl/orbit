<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build;

/**
 * One rendered Node Caddyfile. A render with problems must never be pushed.
 */
final readonly class NodeCaddyfile
{
    /**
     * @param  list<CaddySite>  $sites
     * @param  list<string>  $problems
     */
    public function __construct(
        public string $nodeName,
        public string $content,
        public string $version,
        public array $sites,
        public array $problems,
    ) {}

    public function buildable(): bool
    {
        return $this->problems === [];
    }
}
