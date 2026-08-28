<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\E2E\TopologyAcquirer;
use App\E2E\Value\TopologyRequest;
use Illuminate\Console\Command;
use Throwable;

final class SyncCommand extends Command
{
    #[\Override]
    protected $signature = 'topology:sync {issue} {worktree} {--json}';
    #[\Override]
    protected $description = 'Synchronize one disposable feature topology';

    public function handle(TopologyAcquirer $acquirer): int
    {
        try {
            $topology = $acquirer->sync(
                new TopologyRequest((string) $this->argument('issue'), (string) $this->argument('worktree')),
            );
            $payload = [
                'state' => 'ready',
                'operation_id' => $topology->source->operationId,
                'evidence_id' => $topology->source->operationId,
            ];
            $this->line($this->option('json') ? json_encode($payload, JSON_THROW_ON_ERROR) : 'ready');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->failure($exception);

            return self::FAILURE;
        }
    }

    private function failure(Throwable $exception): void
    {
        $identity = bin2hex(random_bytes(16));
        $this->option('json')
            ? $this->line(json_encode([
                'state' => 'failed',
                'operation_id' => $identity,
                'evidence_id' => $identity,
                'error' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR))
            : $this->error($exception->getMessage());
    }
}
