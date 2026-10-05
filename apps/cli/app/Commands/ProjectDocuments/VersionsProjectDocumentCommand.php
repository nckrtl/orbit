<?php

declare(strict_types=1);

namespace App\Commands\ProjectDocuments;

final class VersionsProjectDocumentCommand extends ProjectDocumentCommand
{
    #[\Override]
    protected $signature = 'project:document:versions {project? : Project ID or slug} {entry? : Entry ID} {--cursor= : Page cursor} {--limit=50 : Page size} {--json : Return the API envelope}';

    #[\Override]
    protected $description = 'Versions Project Documents.';

    #[\Override]
    protected string $verb = 'versions';
}
