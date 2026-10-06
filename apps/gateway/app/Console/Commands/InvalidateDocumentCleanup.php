<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ProjectDocuments\DocumentCleanupControlAction;

final class InvalidateDocumentCleanup extends DocumentCleanupCommand
{
    #[\Override]
    protected $signature = 'project-documents:cleanup:invalidate';

    #[\Override]
    protected $description = 'Invalidate document-body cleanup before starting Gateway services.';

    #[\Override]
    protected $hidden = true;

    public function handle(DocumentCleanupControlAction $action): int
    {
        return $this->control($action, true, false);
    }
}
