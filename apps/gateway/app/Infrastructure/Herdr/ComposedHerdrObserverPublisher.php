<?php

declare(strict_types=1);

namespace App\Infrastructure\Herdr;

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Herdr\HerdrObserveContract;
use App\Domain\Herdr\HerdrObserverPublication;
use App\Domain\Herdr\HerdrObserverPublisher;
use App\Models\HerdrSession;
use App\Models\Node;
use Throwable;

final readonly class ComposedHerdrObserverPublisher implements HerdrObserverPublisher
{
    public function __construct(
        private HerdrObserveContract $contract,
        private HerdrObserverCaddyRenderer $caddy,
        private HerdrObserverSitePublisher $sites,
        private PrivateDnsManager $dns,
    ) {}

    public function publish(HerdrSession $session, Node $node): HerdrObserverPublication
    {
        $url = $this->contract->observerUrl($session->observer_hostname);

        try {
            $this->sites->publish($session, $node, $this->caddy->render($session));
            $this->dns->converge();
        } catch (Throwable) {
            return new HerdrObserverPublication(
                url: $url,
                published: false,
                error: 'observer publication failed',
            );
        }

        return new HerdrObserverPublication($url, true);
    }

    public function retract(HerdrSession $session, Node $node): void
    {
        $this->sites->retract($session, $node);
        $this->dns->converge();
    }
}
