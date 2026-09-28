<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build\Sources;

use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Caddy\Build\CaddyListenerRule;
use App\Infrastructure\Caddy\Build\CaddySite;
use App\Infrastructure\Caddy\Build\NodeCaddySiteSource;
use App\Models\Node;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Every Route site on a Node: `app-dev` and `app-prod` workload and Router sites, custom proxy
 * Routes, analytics tracking hosts, Agentation, Vite, hibernation wake, and public Ingress sites.
 * The stored Route state decides the sites; the existing renderer draws each one.
 */
final readonly class RouteCaddySiteSource implements NodeCaddySiteSource
{
    public const string BindPlaceholder = '__ORBIT_APP_BIND__';

    public function __construct(
        private DevelopmentSiteRepository $repository,
        private DevelopmentCaddyConfigRenderer $renderer,
    ) {}

    public function sites(Node $node): array
    {
        return $this->fromSites($this->repository->forNode($node));
    }

    /**
     * @param  Collection<int, DevelopmentSite>  $sites
     * @return list<CaddySite>
     */
    public function fromSites(Collection $sites): array
    {
        return array_values($sites
            ->sortBy(static fn (DevelopmentSite $site): string => $site->domain."\0".$site->scope)
            ->map(function (DevelopmentSite $site): CaddySite {
                $name = $site->scope;

                if ($name === '') {
                    throw new LogicException('A Caddy site scope must not be empty.');
                }

                return new CaddySite(
                    source: $this->source($site),
                    name: $name,
                    listener: $site->publicListener ? CaddyListenerRule::Public : CaddyListenerRule::Wildcard,
                    hosts: [$site->domain],
                    port: 443,
                    body: $this->renderer->render(collect([$site]), self::BindPlaceholder, self::BindPlaceholder),
                    bindPlaceholder: self::BindPlaceholder,
                    unixSockets: is_string($site->localUnixUpstream) && $site->localUnixUpstream !== ''
                        ? [$site->localUnixUpstream]
                        : [],
                );
            })
            ->all());
    }

    /** @return non-empty-string */
    private function source(DevelopmentSite $site): string
    {
        return match (true) {
            $site->publicListener => 'ingress',
            $site->environment === 'production' => 'app-prod',
            default => 'app-dev',
        };
    }
}
