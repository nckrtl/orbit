<?php

declare(strict_types=1);

namespace App\Console\Commands\TopologySnapshot;

use App\Console\Commands\E2ECommand;
use App\E2E\TopologySnapshotRefresher;
use Throwable;

final class RestoreCommand extends E2ECommand
{
    #[\Override]
    protected $signature = 'topology-snapshot:restore {--json}';
    #[\Override]
    protected $description = 'Restore the promoted topology snapshot generation and leave it stopped';

    public function handle(TopologySnapshotRefresher $refresher): int
    {
        try {
            $generation = $refresher->restore();
            $payload = ['state' => 'restored', 'generation_id' => $generation->id];
            $this->outputJson($payload, $generation->id);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->outputFailure($exception);

            return self::FAILURE;
        }
    }
}
