<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tasks\ArchiveFinishedTaskThreads;
use Illuminate\Console\Command;

final class ArchiveTaskThreadsCommand extends Command
{
    #[\Override]
    protected $signature = 'tasks:archive-threads';

    #[\Override]
    protected $description = 'Archive T3 threads for finished tasks and groups';

    public function handle(ArchiveFinishedTaskThreads $archive): int
    {
        $archive->run();

        return self::SUCCESS;
    }
}
