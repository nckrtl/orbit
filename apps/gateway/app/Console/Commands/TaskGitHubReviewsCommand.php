<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tasks\TaskGitHubReviewObservations;
use App\Models\Task;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

final class TaskGitHubReviewsCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:tasks:github-reviews {group-id : Stored task group ID} {--json : Print local approval evidence as JSON}';

    #[\Override]
    protected $description = 'Inspect stored GitHub approval evidence without contacting GitHub or granting approval';

    public function handle(TaskGitHubReviewObservations $observations): int
    {
        $id = (string) $this->argument('group-id');
        $group = ctype_digit($id) ? Task::topLevel()->find($id) : null;
        if ($group === null) {
            $this->error('Unknown task group.');

            return self::FAILURE;
        }
        $this->output->writeln(json_encode($observations->report($group), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
