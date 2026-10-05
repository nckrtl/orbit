<?php

declare(strict_types=1);

namespace App\Commands\ProjectDocuments;

final class RestoreProjectDocumentCommand extends ProjectDocumentCommand
{
    #[\Override]
    protected $signature = 'project:document:restore {project? : Project ID or slug} {entry? : Entry ID} {--expected-revision= : Observed revision} {--json : Return the API envelope}';

    #[\Override]
    protected $description = 'Restore Project Documents.';

    #[\Override]
    protected string $verb = 'restore';
}
