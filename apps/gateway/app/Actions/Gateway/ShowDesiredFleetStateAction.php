<?php

declare(strict_types=1);

namespace App\Actions\Gateway;

use App\Data\Fleet\DesiredFleetStateData;
use App\Domain\Fleet\DesiredFleetState;

final readonly class ShowDesiredFleetStateAction
{
    public function __construct(private DesiredFleetState $state) {}

    public function handle(): DesiredFleetStateData
    {
        return $this->state->current();
    }
}
