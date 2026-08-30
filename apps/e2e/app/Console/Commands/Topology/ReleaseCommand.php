<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\TopologyReleaser;
use App\E2E\Value\AttemptId;
use App\E2E\Value\OperationId;
use Throwable;

final class ReleaseCommand extends E2ECommand
{
    #[\Override]
    protected $signature = 'topology:release {issue} {attempt} {--json}';
    #[\Override]
    protected $description = 'Release one exact disposable topology attempt';

    public function handle(TopologyReleaser $releaser, OperationId $operation): int
    {
        try {
            $result = $releaser->release(
                (string) $this->argument('issue'),
                new AttemptId((string) $this->argument('attempt')),
            );
            $this->outputJson($result->toArray(), 'released');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->outputFailure($exception, $operation);

            return self::FAILURE;
        }
    }
}
