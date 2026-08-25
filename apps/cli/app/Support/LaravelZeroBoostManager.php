<?php

declare(strict_types=1);

namespace App\Support;

use Laravel\Boost\BoostManager;

final class LaravelZeroBoostManager extends BoostManager
{
    public function getAgents(): array
    {
        $agents = parent::getAgents();
        $agents['codex'] = LaravelZeroCodex::class;

        return $agents;
    }
}
