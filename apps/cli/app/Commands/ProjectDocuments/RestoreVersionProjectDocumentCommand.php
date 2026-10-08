<?php

declare(strict_types=1);

namespace App\Commands\ProjectDocuments;

final class RestoreVersionProjectDocumentCommand extends ProjectDocumentCommand
{
    #[\Override]
    protected $signature = 'project:document:restore-version {project? : Project ID or slug} {entry? : Entry ID} {--version= : Version ID} {--expected-revision= : Observed revision} {--json : Return the API envelope}';

    #[\Override]
    protected $description = 'Restore-version Project Documents.';

    #[\Override]
    protected string $verb = 'restore-version';
}
