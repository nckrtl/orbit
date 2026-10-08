<?php

declare(strict_types=1);

namespace App\Commands\ProjectDocuments;

final class ListProjectDocumentCommand extends ProjectDocumentCommand
{
    #[\Override]
    protected $signature = 'project:document:list {project? : Project ID or slug} {--parent= : Parent folder ID} {--state=active : Archive state} {--kind= : Entry kind} {--cursor= : Page cursor} {--limit=50 : Page size} {--json : Return the API envelope}';

    #[\Override]
    protected $description = 'List Project Documents.';

    #[\Override]
    protected string $verb = 'list';
}
