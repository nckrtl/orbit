<?php

declare(strict_types=1);

namespace App\Console\Commands\Standby;

use App\E2E\StandbyRefresher;
use Illuminate\Console\Command;
use Throwable;

final class RestoreCommand extends Command
{
    #[\Override]
    protected $signature = 'standby:restore {--json}';
    #[\Override]
    protected $description = 'Restore the promoted standby generation and leave it stopped';

    public function handle(StandbyRefresher $refresher): int
    {
        try {
            $generation = $refresher->restore();
            $payload = ['state' => 'restored', 'generation_id' => $generation->id];
            $this->line($this->option('json') ? json_encode($payload, JSON_THROW_ON_ERROR) : $generation->id);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
