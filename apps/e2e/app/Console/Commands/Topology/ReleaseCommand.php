<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\E2E\TopologyReleaser;
use Illuminate\Console\Command;
use Throwable;

final class ReleaseCommand extends Command
{
    #[\Override]
    protected $signature = 'topology:release {issue} {--json}';
    #[\Override]
    protected $description = 'Release one exact disposable feature topology';

    public function handle(TopologyReleaser $releaser): int
    {
        try {
            $result = $releaser->release((string) $this->argument('issue'));
            $this->line($this->option('json') ? json_encode($result->toArray(), JSON_THROW_ON_ERROR) : 'released');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $identity = bin2hex(random_bytes(16));
            $this->option('json')
                ? $this->line(json_encode([
                    'state' => 'failed',
                    'operation_id' => $identity,
                    'evidence_id' => $identity,
                    'error' => $exception->getMessage(),
                ], JSON_THROW_ON_ERROR))
                : $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
