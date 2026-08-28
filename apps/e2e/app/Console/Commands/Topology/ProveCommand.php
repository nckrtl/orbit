<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\E2E\TopologyAcquirer;
use App\E2E\Value\TopologyRequest;
use Illuminate\Console\Command;
use Throwable;

final class ProveCommand extends Command
{
    #[\Override]
    protected $signature = 'topology:prove {issue} {worktree} {--candidate-sha=} {--json}';
    #[\Override]
    protected $description = 'Prove one clean feature candidate';

    public function handle(TopologyAcquirer $acquirer): int
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
            $this->line($this->option('json') ? json_encode($result->toArray(), JSON_THROW_ON_ERROR) : 'proved');

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
