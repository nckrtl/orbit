<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Fleet\FleetRolloutRunner;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Runs one pass of the fleet rollout and catch-up (ADR 0202). `orbit-fleet-converge.service` runs it;
 * it never runs inside PHP-FPM or the scheduler. It prints one JSON line and exits 0 unless the run
 * itself broke; a halted rollout is a recorded outcome, not a command failure.
 */
final class FleetConvergeCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:fleet-converge';

    #[\Override]
    protected $description = 'Roll the desired fleet state out to the managed Nodes one at a time, or catch up the Nodes that lag.';

    public function handle(FleetRolloutRunner $runner): int
    {
        try {
            $summary = $runner->run();
        } catch (Throwable $exception) {
            report($exception);
            $this->output->writeln(json_encode(['status' => 'error', 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

            return self::FAILURE;
        }

        $this->output->writeln(json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
