<?php

declare(strict_types=1);

namespace App\Commands\ProjectDocuments;

final class ShowProjectDocumentStorageCommand extends ProjectDocumentStorageCommand
{
    #[\Override]
    protected $signature = 'project:document-storage:show {--json : Return the API envelope}';

    #[\Override]
    protected $description = 'Show private Project Document storage.';

    #[\Override]
    protected bool $updateStorage = false;
}
