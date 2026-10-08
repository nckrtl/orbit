<?php

declare(strict_types=1);

namespace App\Commands\ProjectDocuments;

final class UploadProjectDocumentCommand extends ProjectDocumentCommand
{
    #[\Override]
    protected $signature = 'project:document:upload {project? : Project ID or slug} {entry? : Entry ID} {--from= : Local file or - for stdin} {--media-type= : Media type} {--expected-revision= : Observed revision} {--json : Return the API envelope}';

    #[\Override]
    protected $description = 'Upload Project Documents.';

    #[\Override]
    protected string $verb = 'upload';
}
