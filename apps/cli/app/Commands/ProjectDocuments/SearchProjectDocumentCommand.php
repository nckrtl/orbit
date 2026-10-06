<?php

declare(strict_types=1);

namespace App\Commands\ProjectDocuments;

final class SearchProjectDocumentCommand extends ProjectDocumentCommand
{
    #[\Override]
    protected $signature = 'project:document:search {project? : Project ID or slug} {query? : Name or path substring} {--state=active : Archive state} {--kind= : Entry kind} {--cursor= : Page cursor} {--limit=50 : Page size} {--json : Return the API envelope}';

    #[\Override]
    protected $description = 'Search Project Documents.';

    #[\Override]
    protected string $verb = 'search';
}
