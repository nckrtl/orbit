<?php

declare(strict_types=1);

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Herdr\HerdrObserveContract;
use App\Infrastructure\Herdr\ComposedHerdrObserverPublisher;
use App\Infrastructure\Herdr\HerdrObserverCaddyRenderer;
use App\Infrastructure\Herdr\HerdrObserverSitePublisher;
use App\Models\HerdrSession;
use App\Models\Node;

it('retracts the observer site when private DNS publication fails', function (): void {
    $sites = new ComposedHerdrSiteFake;
    $publisher = composed_herdr_publisher($sites, new ComposedHerdrDnsFake(fail: true));

    $publication = $publisher->publish(composed_herdr_session(), composed_herdr_node());

    expect($publication->published)
        ->toBeFalse()
        ->and($publication->error)
        ->toBe('observer publication failed')
        ->and($sites->published)
        ->toBe(1)
        ->and($sites->retracted)
        ->toBe(1);
});

it('reports a rollback failure when DNS and observer retraction both fail', function (): void {
    $sites = new ComposedHerdrSiteFake(failRetract: true);
    $publisher = composed_herdr_publisher($sites, new ComposedHerdrDnsFake(fail: true));

    $publication = $publisher->publish(composed_herdr_session(), composed_herdr_node());

    expect($publication->published)
        ->toBeFalse()
        ->and($publication->error)
        ->toBe('observer publication rollback failed')
        ->and($sites->published)
        ->toBe(1)
        ->and($sites->retracted)
        ->toBe(1);
});

function composed_herdr_publisher(
    HerdrObserverSitePublisher $sites,
    PrivateDnsManager $dns,
): ComposedHerdrObserverPublisher {
    return new ComposedHerdrObserverPublisher(
        new HerdrObserveContract,
        new HerdrObserverCaddyRenderer,
        $sites,
        $dns,
    );
}

function composed_herdr_session(): HerdrSession
{
    return new HerdrSession([
        'session' => 'commander-tasks',
        'observer_hostname' => 'commander-tasks.herdr.beast.orbit',
        'observer_port' => 7411,
    ]);
}

function composed_herdr_node(): Node
{
    return new Node(['name' => 'beast']);
}

final class ComposedHerdrSiteFake implements HerdrObserverSitePublisher
{
    public int $published = 0;

    public int $retracted = 0;

    public function __construct(private readonly bool $failRetract = false) {}

    public function publish(HerdrSession $session, Node $node, string $caddyConfiguration): void
    {
        $this->published++;
    }

    public function retract(HerdrSession $session, Node $node): void
    {
        $this->retracted++;

        if ($this->failRetract) {
            throw new RuntimeException('retract failed');
        }
    }
}

final class ComposedHerdrDnsFake implements PrivateDnsManager
{
    public function __construct(private readonly bool $fail) {}

    public function converge(?Node $pendingNode = null): void
    {
        if ($this->fail) {
            throw new RuntimeException('DNS failed');
        }
    }
}
