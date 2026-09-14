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
    protected $description = 'Halt idle app-dev AppInstance Processes and prune reconstructable checkout dependencies after the configured idle windows.';

    public function handle(SweepIdleAppDevRuntimesAction $sweep): int
    {
        try {
            $result = $sweep->execute();
        } catch (HibernationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Runtime hibernator failed.');

            return self::FAILURE;
        }

        $this->info("Halted [{$result->halted}] idle app-dev AppInstance runtime groups.");
        $this->info("Pruned [{$result->pruned}] cold app-dev AppInstance dependency trees.");

        return self::SUCCESS;
    }
}
