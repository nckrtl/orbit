<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ProjectDocuments\WorkDocumentCleanupAction;
use Symfony\Component\Console\Output\OutputInterface;

final class WorkDocumentCleanup extends DocumentCleanupCommand
{
    #[\Override]
    protected $signature = 'project-documents:cleanup:work';

    #[\Override]
    protected $description = 'Run one bounded batch of authorized document-body deletions';

    public function handle(WorkDocumentCleanupAction $action): int
    {
        $data = $action->handle();
        $this->output->writeln(json_encode($data, JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

        return isset($data['error_code']) ? self::FAILURE : self::SUCCESS;
    }
}
