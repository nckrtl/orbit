<?php

declare(strict_types=1);

namespace Tests\Support\Fleet;

use App\Domain\Fleet\FleetConvergeUnits;

final class FakeFleetConvergeUnits implements FleetConvergeUnits
{
    public int $converged = 0;

    public int $started = 0;

    public function converge(): void
    {
        $this->converged++;
    }

    public int $startedLater = 0;

    public function start(): bool
    {
        $this->started++;

        return true;
    }

    public function startLater(): bool
    {
        $this->startedLater++;

        return true;
    }
}
