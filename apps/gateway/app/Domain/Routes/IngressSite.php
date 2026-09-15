<?php

declare(strict_types=1);

namespace App\Domain\Routes;

final readonly class IngressSite
{
    public function __construct(
        public string $domain,
        public string $routerUpstream,
        public int $ingressNodeId,
        public string $certificateScope,
        public bool $activated,
    ) {}

    /** @return array{domain: string, router_upstream: string} */
    public function artifact(): array
    {
        return [
            'domain' => $this->domain,
            'router_upstream' => $this->routerUpstream,
        ];
    }

    public function certificateDirectory(): string
    {
        return "/etc/caddy/orbit-certificates/{$this->certificateScope}/current";
    }
}
