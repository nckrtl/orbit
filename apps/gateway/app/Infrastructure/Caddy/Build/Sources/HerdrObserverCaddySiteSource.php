<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build\Sources;

use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Caddy\Build\CaddyListenerRule;
use App\Infrastructure\Caddy\Build\CaddySite;
use App\Infrastructure\Caddy\Build\NodeCaddySiteSource;
use App\Infrastructure\Herdr\HerdrObserverCaddyRenderer;
use App\Models\HerdrSession;
use App\Models\Node;

/**
 * One observer site for each Herdr session on the Node that publishes its observer. A session serves
 * while its observer is being published or is published, until its removal starts.
 */
final readonly class HerdrObserverCaddySiteSource implements NodeCaddySiteSource
{
    public const string BindPlaceholder = '__ORBIT_HERDR_BIND__';

    public function __construct(
        private HerdrObserverCaddyRenderer $renderer = new HerdrObserverCaddyRenderer,
    ) {}

    public function sites(Node $node): array
    {
        return HerdrSession::query()
            ->where('node_id', $node->id)
            ->where('publish_observer', true)
            ->whereIn('observer_status', ['pending', 'published'])
            ->where('status', '!=', LifecycleStatus::Removing->value)
            ->orderBy('observer_hostname')
            ->orderBy('id')
            ->get()
            ->map(fn (HerdrSession $session): CaddySite => new CaddySite(
                source: 'herdr',
                name: $session->session,
                listener: CaddyListenerRule::Shared,
                hosts: [$session->observer_hostname],
                port: 443,
                body: $this->renderer->render($session, self::BindPlaceholder),
                bindPlaceholder: self::BindPlaceholder,
            ))
            ->values()
            ->all();
    }
}
