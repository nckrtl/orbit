<?php

declare(strict_types=1);

namespace App\Commands\ProjectDocuments;

final class ArchiveProjectDocumentCommand extends ProjectDocumentCommand
{
    #[\Override]
    protected $signature = 'project:document:archive {project? : Project ID or slug} {entry? : Entry ID} {--expected-revision= : Observed revision} {--json : Return the API envelope}';

    #[\Override]
    protected $description = 'Archive Project Documents.';

    #[\Override]
    protected string $verb = 'archive';
}
