<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\E2E\TopologyReaper;
use App\E2E\Value\IssueStateSnapshot;
use Illuminate\Console\Command;
use Throwable;

final class ReapCommand extends Command
{
    #[\Override]
    protected $signature = 'topology:reap {--issue-state-file=} {--json}';
    #[\Override]
    protected $description = 'Reap expired terminal issue topologies';

    public function handle(TopologyReaper $reaper): int
    {
        try {
            $path = $this->option('issue-state-file');
            if (! is_string($path)) {
                throw new \InvalidArgumentException('The issue state snapshot is required.');
            }
            $results = array_map(
                static fn ($result): array => $result->toArray(),
                $reaper->reap(IssueStateSnapshot::fromFile($path)),
            );
            $identity = bin2hex(random_bytes(16));
            $payload = [
                'state' => 'reaped',
                'operation_id' => $identity,
                'evidence_id' => $identity,
                'results' => $results,
            ];
            $this->line($this->option('json') ? json_encode($payload, JSON_THROW_ON_ERROR) : 'reaped '.count($results));

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
