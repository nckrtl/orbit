<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Hibernation\SweepIdleAppDevRuntimesAction;
use App\Domain\Hibernation\HibernationException;
use Illuminate\Console\Command;
use Throwable;

final class RuntimeHibernatorCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:runtime-hibernator';

    #[\Override]
    protected $description = 'Halt idle app-dev AppInstance Processes after the configured HTTP idle window.';

    public function handle(SweepIdleAppDevRuntimesAction $sweep): int
    {
        try {
            $halted = $sweep->execute();
        } catch (HibernationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Runtime hibernator failed.');

            return self::FAILURE;
        }

        $this->info("Halted [{$halted}] idle app-dev AppInstance runtime groups.");

        return self::SUCCESS;
    }
}
