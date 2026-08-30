<?php

declare(strict_types=1);

namespace App\Console\Commands\Standby;

use App\Console\Commands\E2ECommand;
use App\E2E\StandbyPromoter;
use App\E2E\Value\ProofPlan;
use App\E2E\Value\TopologyRequest;
use Throwable;

final class PromoteCommand extends E2ECommand
{
    #[\Override]
    protected $signature =
        'standby:promote {issue} '
            .self::WORKTREE_OPTION
            .' {--plan= : The proof plan of the proved attempt; defaults to proofs/<ISSUE>.json in the worktree} {--json}';
    #[\Override]
    protected $description = 'Promote the proved topology of the issue to the standby generation and release it';

    public function handle(StandbyPromoter $promoter): int
    {
        try {
            $request = $this->request();
            $result = $promoter->promote($request, ProofPlan::fromFile($this->planPath($request)));
            $this->log($request, 'attempt='.$result['attempt_id'].' generation='.$result['generation_id'].' ok');
            $this->outputJson($result, 'promoted '.$result['generation_id']);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if (isset($request)) {
                $this->log($request, 'failed: '.$exception->getMessage());
            }
            $this->outputFailure($exception);

            return self::FAILURE;
        }
    }

    private function planPath(TopologyRequest $request): string
    {
        $plan = $this->option('plan');
        if (! is_string($plan) || $plan === '') {
            $plan = 'proofs/'.$request->issue.'.json';
        }

        return str_starts_with($plan, '/') ? $plan : $request->worktree.'/'.$plan;
    }
}
