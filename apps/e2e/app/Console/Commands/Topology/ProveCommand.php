<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\TopologyAcquirer;
use App\E2E\Value\OperationId;
use App\E2E\Value\TopologyRequest;
use Throwable;

final class ProveCommand extends E2ECommand
{
    #[\Override]
    protected $signature = 'topology:prove {issue} {worktree} {--candidate-sha=} {--json}';
    #[\Override]
    protected $description = 'Prove one clean feature candidate';

    public function handle(TopologyAcquirer $acquirer, OperationId $operation): int
    {
        try {
            $sha = $this->option('candidate-sha');
            if (! is_string($sha)) {
                throw new \InvalidArgumentException('The exact candidate SHA is required.');
            }
            $result = $acquirer->prove(
                new TopologyRequest((string) $this->argument('issue'), (string) $this->argument('worktree')),
                $sha,
            );
            $this->outputJson($result->toArray(), 'proved');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->outputFailure($exception, $operation);

            return self::FAILURE;
        }
    }
}
