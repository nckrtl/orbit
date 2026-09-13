<?php

declare(strict_types=1);

namespace App\Infrastructure\Herdr;

use App\Models\HerdrSession;
use App\Models\Node;

final class RecordingHerdrObserverSitePublisher implements HerdrObserverSitePublisher
{
    /** @var list<array{action: string, session: string, hostname: string}> */
    public array $events = [];

    public function publish(HerdrSession $session, Node $node, string $caddyConfiguration): void
    {
        $this->events[] = [
            'action' => 'publish',
            'session' => $session->session,
            'hostname' => $session->observer_hostname,
            'caddy' => $caddyConfiguration,
            'node' => $node->name,
        ];
    }

    public function retract(HerdrSession $session, Node $node): void
    {
        $this->events[] = [
            'action' => 'retract',
            'session' => $session->session,
            'hostname' => $session->observer_hostname,
            'caddy' => '',
            'node' => $node->name,
        ];
    }
}
