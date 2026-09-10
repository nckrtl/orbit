<?php

declare(strict_types=1);

namespace App\Console\Commands\Scenario;

use App\E2E\ScenarioRecovery;
use App\E2E\Value\AttemptId;
use App\E2E\Value\ScenarioId;
use App\E2E\Value\ScenarioRunId;
use Illuminate\Console\Command;
use Throwable;

final class CleanupCommand extends Command
{
    #[\Override]
    protected $signature = 'scenario:cleanup {run} {scenario} {attempt} {--json}';

    #[\Override]
    protected $description = 'Retry exact cleanup for one retained scenario attempt';

    public function handle(ScenarioRecovery $recovery): int
    {
        try {
            $run = $this->argument('run');
            $scenario = $this->argument('scenario');
            $attempt = $this->argument('attempt');
            $result = $recovery->cleanup(new ScenarioRunId($run), new ScenarioId($scenario), new AttemptId($attempt));
            $this->line($this->option('json')
                ? json_encode($result->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
                : ($result->successful() ? 'scenario cleanup passed' : 'scenario cleanup failed'));

            return $result->successful() ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
