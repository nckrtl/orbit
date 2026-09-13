<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Herdr\HerdrObserverPublication;
use App\Domain\Herdr\HerdrObserverPublisher;
use App\Models\HerdrSession;
use App\Models\Node;
use RuntimeException;

final class FakeHerdrObserverPublisher implements HerdrObserverPublisher
{
    /** @var list<string> */
    public array $published = [];

    /** @var list<string> */
    public array $retracted = [];

    public bool $failPublish = false;

    public bool $failRetract = false;

    public function publish(HerdrSession $session, Node $node): HerdrObserverPublication
    {
        if ($this->failPublish) {
            throw new RuntimeException('observer publication failed');
        }

        $this->published[] = $session->session;

        return new HerdrObserverPublication(
            url: 'wss://'.$session->observer_hostname,
            published: true,
        );
    }

    public function retract(HerdrSession $session, Node $node): void
    {
        if ($this->failRetract) {
            throw new RuntimeException('observer retract failed');
        }

        $this->retracted[] = $session->session;
    }
}
