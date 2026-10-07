<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Fleet\DesiredFleetState;
use Illuminate\Console\Command;

final class ResolveDesiredFleetStateCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:desired-fleet-state';

    #[\Override]
    protected $description = 'Resolve and print the desired fleet state of this Gateway: its commit, CLI release, and agent pin.';

    public function handle(DesiredFleetState $state): int
    {
        $this->line(json_encode($state->current()->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
