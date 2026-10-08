<?php

declare(strict_types=1);

namespace App\Commands\ProjectDocuments;

final class DownloadProjectDocumentCommand extends ProjectDocumentCommand
{
    #[\Override]
    protected $signature = 'project:document:download {project? : Project ID or slug} {entry? : Entry ID} {--version= : Version ID} {--output= : New file path or - for stdout} {--json : Return the API envelope}';

    #[\Override]
    protected $description = 'Download Project Documents.';

    #[\Override]
    protected string $verb = 'download';
}
