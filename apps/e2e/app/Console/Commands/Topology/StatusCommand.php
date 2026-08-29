<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\TopologyManifestStore;
use App\E2E\Value\AttemptId;
use App\E2E\Value\OperationId;
use App\E2E\Value\TopologyTarget;
use Throwable;

/** Read-only: reports the active attempt of an issue, or one exact attempt record. */
final class StatusCommand extends E2ECommand
{
    #[\Override]
    protected $signature = 'topology:status {issue} {attempt?} {--json}';
    #[\Override]
    protected $description = 'Report the active or exact topology attempt without touching infrastructure';

    public function handle(TopologyManifestStore $manifests, OperationId $operation): int
    {
        try {
            $issue = (string) $this->argument('issue');
            TopologyTarget::assertIssue($issue);
            $attemptArgument = $this->argument('attempt');
            $attempt = is_string($attemptArgument) ? new AttemptId($attemptArgument) : null;
            $topology = $attempt === null ? $manifests->active($issue) : $manifests->read($issue, $attempt);

            if ($topology === null) {
                $this->outputJson([
                    'state' => 'absent',
                    'operation_id' => $operation->value,
                    'issue' => $issue,
                    'attempt_id' => $attempt?->value,
                ], 'absent');

                return self::SUCCESS;
            }

            $this->outputJson(
                [
                    'state' => $topology->purpose->value,
                    'operation_id' => $operation->value,
                    'issue' => $issue,
                    'attempt_id' => $topology->attempt->value,
                    'topology' => $topology->toArray(),
                ],
                $topology->purpose->value.' '.$topology->attempt->value,
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->outputFailure($exception, $operation);

            return self::FAILURE;
        }
    }
}
