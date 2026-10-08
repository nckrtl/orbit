<?php

declare(strict_types=1);

namespace App\Commands\ProjectDocuments;

final class RemoveProjectDocumentCommand extends ProjectDocumentCommand
{
    #[\Override]
    protected $signature = 'project:document:remove {project? : Project ID or slug} {entry? : Entry ID} {--expected-revision= : Observed revision} {--recursive : Permanently remove descendants} {--yes : Confirm permanent removal} {--json : Return the API envelope}';

    #[\Override]
    protected $description = 'Remove Project Documents.';

    #[\Override]
    protected string $verb = 'remove';
}
