<?php

declare(strict_types=1);

namespace App\Infrastructure\Herdr;

use App\Models\HerdrSession;
use App\Models\Node;

interface HerdrObserverSitePublisher
{
    public function publish(HerdrSession $session, Node $node, string $caddyConfiguration): void;

    public function retract(HerdrSession $session, Node $node): void;
}
