<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build;

use App\Infrastructure\AppDev\AppDevSite;
use App\Infrastructure\Caddy\Build\Sources\AppCaddySiteSource;
use App\Models\Node;
use Illuminate\Support\Collection;

/**
 * The Node Caddy build's listeners for a Node's Route sites. Only Route sites use the first-row rule, so
 * they alone decide where a shared site joins the wildcard listener.
 */
final readonly class NodeCaddyListenerResolver
{
    public function __construct(
        private AppCaddySiteSource $apps,
    ) {}

    /** @param Collection<int, AppDevSite>|null $sites The Node's Route sites, when the caller already read them. */
    public function forNode(Node $node, ?Collection $sites = null): NodeCaddyListeners
    {
        $routeSites = match (true) {
            $sites instanceof Collection => $this->apps->fromSites($sites),
            $node->exists => $this->apps->sites($node),
            default => [],
        };

        return NodeCaddyListeners::forSites($node, $routeSites);
    }
}
