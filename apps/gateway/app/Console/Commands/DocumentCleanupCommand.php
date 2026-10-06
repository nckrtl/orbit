<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ProjectDocuments\DocumentCleanupControlAction;
use App\Data\ProjectDocuments\CleanupGateStatus;
use Illuminate\Console\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class DocumentCleanupCommand extends Command
{
    public function run(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::run($input, $output);
        } catch (InvalidArgumentException|RuntimeException) {
            $output->writeln(json_encode((new CleanupGateStatus)->toArray() + [
                'pending_cleanup_count' => 0, 'oldest_pending_cleanup_at' => null, 'last_cleanup_error_code' => null,
                'error_code' => 'project_documents.cleanup_input_invalid',
            ], JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

            return 2;
        }
    }

    protected function control(DocumentCleanupControlAction $action, bool $invalidate, bool $includeCounts = true): int
    {
        $data = $action->handle($invalidate, $includeCounts);
        $this->output->writeln(json_encode($data, JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

        return isset($data['error_code']) ? self::FAILURE : self::SUCCESS;
    }
}
