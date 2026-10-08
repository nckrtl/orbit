<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ProjectDocuments\ReconcileProbesAction;
use Illuminate\Console\Command;
use Throwable;

final class ReconcileDocumentProbes extends Command
{
    #[\Override]
    protected $signature = 'project-documents:probes:reconcile';

    #[\Override]
    protected $description = 'Attempt one bounded batch of reserved document probe deletions.';

    public function handle(ReconcileProbesAction $action): int
    {
        try {
            $counts = $action->handle();
            $this->line(json_encode($counts, JSON_THROW_ON_ERROR));

            return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            $this->components->error('Document probe recovery is unavailable. Retained records require operator repair.');

            return self::FAILURE;
        }
    }
}
