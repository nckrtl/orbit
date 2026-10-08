<?php

declare(strict_types=1);

namespace App\Commands\ProjectDocuments;

final class ReadProjectDocumentCommand extends ProjectDocumentCommand
{
    #[\Override]
    protected $signature = 'project:document:read {project? : Project ID or slug} {entry? : Entry ID} {--version= : Version ID} {--json : Return the API envelope}';

    #[\Override]
    protected $description = 'Read Project Documents.';

    #[\Override]
    protected string $verb = 'read';
}
