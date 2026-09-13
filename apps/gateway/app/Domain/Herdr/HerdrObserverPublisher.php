<?php

declare(strict_types=1);

namespace App\Domain\Herdr;

use App\Models\HerdrSession;
use App\Models\Node;

interface HerdrObserverPublisher
{
    public function publish(HerdrSession $session, Node $node): HerdrObserverPublication;

    public function retract(HerdrSession $session, Node $node): void;
}
