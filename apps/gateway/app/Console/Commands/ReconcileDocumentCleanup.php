<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ProjectDocuments\RecoverDocumentCleanupAction;
use Symfony\Component\Console\Output\OutputInterface;

final class ReconcileDocumentCleanup extends DocumentCleanupCommand
{
    #[\Override]
    protected $signature = 'project-documents:cleanup:reconcile {--resolution-file= : Private operator resolution JSON file}';

    #[\Override]
    protected $description = 'Inventory document metadata and bucket objects without changing them';

    public function handle(RecoverDocumentCleanupAction $action): int
    {
        $path = $this->option('resolution-file');
        $data = $action->reconcile(is_string($path) ? $path : null);
        $this->output->writeln(json_encode($data, JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

        return ($data['error_code'] ?? null) === 'project_documents.cleanup_input_invalid' ? 2 : (isset($data['error_code']) ? self::FAILURE : self::SUCCESS);
    }
}
