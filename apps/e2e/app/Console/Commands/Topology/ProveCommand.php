<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\ProofPlanFile;
use App\E2E\TopologyProofRunner;
use App\E2E\Value\ProofResult;
use App\E2E\Value\ProofStatus;
use Throwable;

final class ProveCommand extends E2ECommand
{
    #[\Override]
    protected $signature =
        'topology:prove {issue} '
            .self::WORKTREE_OPTION
            .' {--plan= : The proof plan; defaults to .loop/proof/<ISSUE>.json in the worktree} {--json}';
    #[\Override]
    protected $description = 'Prove the worktree HEAD commit on a fresh topology with the proof plan';

    public function handle(TopologyProofRunner $runner): int
    {
        try {
            $request = $this->request();
            $plan = ProofPlanFile::current($request, $this->option('plan'));
            $result = $runner->prove(
                $request,
                $plan->plan,
                $plan->path,
            );
            $this->log($request, 'attempt='.$result->attempt->value.' '.$result->status->value);
            $this->outputJson($result->toArray(), $this->text($result));

            return $result->status === ProofStatus::Proved ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            if (isset($request)) {
                $this->log($request, 'failed: '.$exception->getMessage());
            }
            $this->outputFailure($exception);

            return self::FAILURE;
        }
    }

    private function text(ProofResult $result): string
    {
        $text = $result->status->value.' '.$result->attempt->value;
        $failed = $result->failedAction();
        if ($failed !== null) {
            $text .=
                " failed_action={$failed['id']} node={$failed['node']} exit_code={$failed['exit_code']}\n"
                .rtrim($failed['stderr_tail']);
        } elseif ($result->error !== null) {
            $text .= "\n".$result->error;
        }

        return $text;
    }
}
