<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\TopologyProofRunner;
use App\E2E\Value\OperationId;
use App\E2E\Value\ProofPlan;
use App\E2E\Value\ProofResult;
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
            $payload = [
                'state' => $result->status->value,
                'operation_id' => $operation->value,
                'issue' => $result->issue,
                'attempt_id' => $result->attempt->value,
                'proof' => $result->summary(),
            ];
            // A diagnosis ends with the action that failed, so a worker reads the verdict without the record file.
            if ($result->status === ProofStatus::Diagnosis) {
                $payload['failed_action'] = $result->failedAction();
            }
            $this->outputJson($payload, $this->text($result));

            return $result->status === ProofStatus::Proved ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->outputFailure($exception, $operation);

            return self::FAILURE;
        }
    }

    private function text(ProofResult $result): string
    {
        $text = $result->status->value.' '.$result->attempt->value;
        $failed = $result->status === ProofStatus::Diagnosis ? $result->failedAction() : null;
        if ($failed === null) {
            return $text;
        }

        return (
            $text
            ." failed_action={$failed['id']} node={$failed['node']} exit_code={$failed['exit_code']}\n"
            .rtrim($failed['stderr_tail'])
        );
    }
}
