<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Problems\ProblemFiler;
use App\Domain\Tasks\TaskExtensionState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class FileProblemsCommand extends Command
{
    #[\Override]
    protected $signature = 'problems:file';

    #[\Override]
    protected $description = 'File Backlog tasks for recurring production problems.';

    public function handle(ProblemFiler $filer, TaskExtensionState $extension): int
    {
        if (! $extension->enabled()) {
            return self::SUCCESS;
        }

        $lock = Cache::lock('orbit:problems:file', 30 * 60);

        if (! $lock->get()) {
            return self::SUCCESS;
        }

        try {
            $failures = $filer->file();
        } finally {
            $lock->release();
        }

        foreach ($failures as $exception) {
            $this->reportOnce($exception);
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    private function reportOnce(Throwable $exception): void
    {
        $key = 'problems:file:reported:'.hash('sha256', $exception::class);

        if (Cache::add($key, true, now()->addHour())) {
            report($exception);
        }
    }
}
