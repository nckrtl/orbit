<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\TopologyReaper;
use App\E2E\Value\IssueStateSnapshot;
use App\E2E\Value\OperationId;
use Throwable;

final class ReapCommand extends E2ECommand
{
    #[\Override]
    protected $signature = 'topology:reap {--issue-state-file=} {--json}';
    #[\Override]
    protected $description = 'Reap expired terminal issue topologies';

    public function handle(TopologyReaper $reaper, OperationId $operation): int
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
            $identity = $operation->value;
            $payload = [
                'state' => 'reaped',
                'operation_id' => $identity,
                'results' => $results,
            ];
            $this->line($this->option('json') ? json_encode($payload, JSON_THROW_ON_ERROR) : 'reaped '.count($results));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->outputFailure($exception, $operation);

            return self::FAILURE;
        }
    }
}
