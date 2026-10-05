<?php

declare(strict_types=1);

namespace App\Commands\ProjectDocuments;

final class WriteProjectDocumentCommand extends ProjectDocumentCommand
{
    #[\Override]
    protected $signature = 'project:document:write {project? : Project ID or slug} {entry? : Entry ID} {--content= : Exact inline text} {--from= : Local file or - for stdin} {--media-type= : Media type} {--expected-revision= : Observed revision} {--json : Return the API envelope}';

    #[\Override]
    protected $description = 'Write Project Documents.';

    #[\Override]
    protected string $verb = 'write';
}
