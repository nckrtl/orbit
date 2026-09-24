<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build\Sources;

use App\Infrastructure\AppDev\AppDevCaddyConfigRenderer;
use App\Infrastructure\AppDev\AppDevSite;
use App\Infrastructure\AppDev\AppDevSiteRepository;
use App\Infrastructure\Caddy\Build\CaddyListenerRule;
use App\Infrastructure\Caddy\Build\CaddySite;
use App\Infrastructure\Caddy\Build\NodeCaddySiteSource;
use App\Models\Node;
use Illuminate\Support\Collection;

/**
 * Every Route site on a Node: `app-dev` and `app-prod` workload and Router sites, custom proxy
 * Routes, analytics tracking hosts, Agentation, Vite, hibernation wake, and public Ingress sites.
 * The stored Route state decides the sites; the existing renderer draws each one.
 */
final readonly class AppCaddySiteSource implements NodeCaddySiteSource
{
    public function __construct(
        private AppDevSiteRepository $repository,
        private AppDevCaddyConfigRenderer $renderer,
    ) {}

    public function sites(Node $node): array
    {
        return $this->fromSites($this->repository->forNode($node));
    }

    /**
     * @param  Collection<int, AppDevSite>  $sites
     * @return list<CaddySite>
     */
    public function fromSites(Collection $sites): array
    {
        return array_values($sites
            ->sortBy(static fn (AppDevSite $site): string => $site->domain."\0".$site->scope)
            ->map(fn (AppDevSite $site): CaddySite => new CaddySite(
                source: $this->source($site),
                name: $site->scope,
                listener: $site->publicListener ? CaddyListenerRule::Public : CaddyListenerRule::Wildcard,
                hosts: [$site->domain],
                port: 443,
                body: $this->renderer->render(collect([$site])),
                unixSockets: is_string($site->localUnixUpstream) && $site->localUnixUpstream !== ''
                    ? [$site->localUnixUpstream]
                    : [],
            ))
            ->all());
    }

    /** @return non-empty-string */
    private function source(AppDevSite $site): string
    {
        return match (true) {
            $site->publicListener => 'ingress',
            $site->environment === 'production' => 'app-prod',
            default => 'app-dev',
        };
    }
}
