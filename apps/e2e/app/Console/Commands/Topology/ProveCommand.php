<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\TopologyProofRunner;
use App\E2E\Value\OperationId;
use App\E2E\Value\ProofPlan;
use App\E2E\Value\ProofStatus;
use App\E2E\Value\TopologyRequest;
use InvalidArgumentException;
use Throwable;

final class ProveCommand extends E2ECommand
{
    #[\Override]
    protected $signature = 'topology:prove {issue} {worktree} {--candidate-sha=} {--proof-plan-file=} {--json}';
    #[\Override]
    protected $description = 'Prove one exact candidate commit on a fresh proof topology';

    public function handle(TopologyProofRunner $runner, OperationId $operation): int
    {
        try {
            $sha = $this->option('candidate-sha');
            if (! is_string($sha) || $sha === '') {
                throw new InvalidArgumentException('The exact candidate SHA is required.');
            }
            $planFile = $this->option('proof-plan-file');
            if (! is_string($planFile) || $planFile === '') {
                throw new InvalidArgumentException('The proof plan file is required.');
            }
            $result = $runner->prove(
                new TopologyRequest((string) $this->argument('issue'), (string) $this->argument('worktree')),
                $sha,
                ProofPlan::fromFile($planFile),
            );
            $this->outputJson(
                [
                    'state' => $result->status->value,
                    'operation_id' => $operation->value,
                    'issue' => $result->issue,
                    'attempt_id' => $result->attempt->value,
                    'proof' => $result->toArray(),
                ],
                $result->status->value.' '.$result->attempt->value,
            );

            return $result->status === ProofStatus::Proved ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->outputFailure($exception, $operation);

            return self::FAILURE;
        }
    }
}
