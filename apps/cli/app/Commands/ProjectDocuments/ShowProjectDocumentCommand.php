<?php

declare(strict_types=1);

namespace App\Commands\ProjectDocuments;

final class ShowProjectDocumentCommand extends ProjectDocumentCommand
{
    #[\Override]
    protected $signature = 'project:document:show {project? : Project ID or slug} {entry? : Entry ID} {--json : Return the API envelope}';

    #[\Override]
    protected $description = 'Show Project Documents.';

    #[\Override]
    protected string $verb = 'show';
}
