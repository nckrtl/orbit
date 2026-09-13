<?php

declare(strict_types=1);

namespace App\Domain\Herdr;

use App\Models\HerdrSession;
use App\Models\Node;

interface HerdrSessionInspector
{
    public function inspect(HerdrSession $session, Node $node): HerdrSessionInspection;

    public function handoff(HerdrSession $session, Node $node): void;
}
