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
     * @param  list<string>  $listenAddresses  Every specific IP address a site binds; the push script checks each exists.
     * @param  list<array{source: string, name: string, block: string}>  $blocks  Each rendered site block exactly as the file holds it.
     */
    public function __construct(
        public string $nodeName,
        public string $content,
        public string $version,
        public array $sites,
        public array $problems,
        public array $listenAddresses = [],
        public array $blocks = [],
    ) {}

    /**
     * The rendered blocks of one site source and name, such as the workload site `app-instance-12`.
     *
     * @return list<string>
     */
    public function blocksFor(string $name): array
    {
        return array_values(array_map(
            static fn (array $block): string => $block['block'],
            array_filter($this->blocks, static fn (array $block): bool => $block['name'] === $name),
        ));
    }

    public function buildable(): bool
    {
        return $this->problems === [];
    }
}
