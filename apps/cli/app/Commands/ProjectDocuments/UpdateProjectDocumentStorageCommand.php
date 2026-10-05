<?php

declare(strict_types=1);

namespace App\Commands\ProjectDocuments;

final class UpdateProjectDocumentStorageCommand extends ProjectDocumentStorageCommand
{
    #[\Override]
    protected $signature = 'project:document-storage:update {--endpoint= : HTTPS storage origin} {--region= : Signing region} {--bucket= : Bucket name} {--access-key-id-file= : Private credential input file} {--secret-access-key-file= : Private credential input file} {--json : Return the API envelope}';

    #[\Override]
    protected $description = 'Update private Project Document storage.';

    #[\Override]
    protected bool $updateStorage = true;
}
