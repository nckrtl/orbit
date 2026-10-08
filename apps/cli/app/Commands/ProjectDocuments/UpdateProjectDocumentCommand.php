<?php

declare(strict_types=1);

namespace App\Commands\ProjectDocuments;

final class UpdateProjectDocumentCommand extends ProjectDocumentCommand
{
    #[\Override]
    protected $signature = 'project:document:update {project? : Project ID or slug} {entry? : Entry ID} {--name= : New name} {--parent= : Destination folder ID or root} {--expected-revision= : Observed revision} {--json : Return the API envelope}';

    #[\Override]
    protected $description = 'Update Project Documents.';

    #[\Override]
    protected string $verb = 'update';
}
