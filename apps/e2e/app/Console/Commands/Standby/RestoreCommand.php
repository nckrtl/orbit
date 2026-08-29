<?php

declare(strict_types=1);

namespace App\Console\Commands\Standby;

use App\Console\Commands\E2ECommand;
use App\E2E\StandbyRefresher;
use App\E2E\Value\OperationId;
use Throwable;

final class RestoreCommand extends E2ECommand
{
    #[\Override]
    protected $signature = 'standby:restore {--json}';
    #[\Override]
    protected $description = 'Restore the promoted standby generation and leave it stopped';

    public function handle(StandbyRefresher $refresher, OperationId $operation): int
    {
        try {
            $generation = $refresher->restore();
            $payload = ['state' => 'restored', 'operation_id' => $operation->value, 'generation_id' => $generation->id];
            $this->outputJson($payload, $generation->id);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->outputFailure($exception, $operation);

            return self::FAILURE;
        }
    }
}
