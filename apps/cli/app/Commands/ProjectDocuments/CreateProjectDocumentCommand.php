<?php

declare(strict_types=1);

namespace App\Commands\ProjectDocuments;

final class CreateProjectDocumentCommand extends ProjectDocumentCommand
{
    #[\Override]
    protected $signature = 'project:document:create {project? : Project ID or slug} {name? : Document name} {--kind= : folder or file} {--parent= : Parent folder ID} {--from= : Local file or - for stdin} {--content= : Exact inline text} {--media-type= : Media type} {--json : Return the API envelope}';

    #[\Override]
    protected $description = 'Create Project Documents.';

    #[\Override]
    protected string $verb = 'create';
}
