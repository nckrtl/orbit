<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ProjectDocuments\DocumentCleanupControlAction;

final class PauseDocumentCleanup extends DocumentCleanupCommand
{
    #[\Override]
    protected $signature = 'project-documents:cleanup:pause';

    #[\Override]
    protected $description = 'Pause document-body cleanup and invalidate recovery authorization.';

    public function handle(DocumentCleanupControlAction $action): int
    {
        return $this->control($action, true);
    }
}
