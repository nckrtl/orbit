<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Activities\FinalizeInterruptedActivitiesAction;
use Illuminate\Console\Command;

final class FinalizeInterruptedActivitiesCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:activity-finalize-interrupted';

    #[\Override]
    protected $description = 'Mark Activity rows of killed requests as failed with activity.interrupted.';

    public function handle(FinalizeInterruptedActivitiesAction $action): int
    {
        $finalized = $action->execute();

        $this->line("Finalized {$finalized} interrupted Activity records.");

        return self::SUCCESS;
    }
}
