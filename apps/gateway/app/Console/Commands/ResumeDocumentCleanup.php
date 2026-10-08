<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ProjectDocuments\RecoverDocumentCleanupAction;
use Symfony\Component\Console\Output\OutputInterface;

final class ResumeDocumentCleanup extends DocumentCleanupCommand
{
    #[\Override]
    protected $signature = 'project-documents:cleanup:resume {--report= : Current private reconciliation report ID}';

    #[\Override]
    protected $description = 'Authorize cleanup only after unchanged clean inventory verification';

    public function handle(RecoverDocumentCleanupAction $action): int
    {
        $id = $this->option('report');
        $data = $action->resume(is_string($id) ? $id : null);
        $this->output->writeln(json_encode($data, JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

        return ($data['error_code'] ?? null) === 'project_documents.cleanup_input_invalid' ? 2 : (isset($data['error_code']) ? self::FAILURE : self::SUCCESS);
    }
}
