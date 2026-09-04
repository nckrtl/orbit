<?php

declare(strict_types=1);

namespace App\Console\Commands\TopologySnapshot;

use App\Console\Commands\E2ECommand;
use App\E2E\ProofPlanFile;
use App\E2E\TopologySnapshotPromoter;
use Throwable;

final class PromoteCommand extends E2ECommand
{
    #[\Override]
    protected $signature =
        'topology-snapshot:promote {issue} '
            .self::WORKTREE_OPTION
            .' {--plan= : The proof plan of the proved attempt; defaults to .loop/proof/<ISSUE>.json} {--json}';
    #[\Override]
    protected $description = 'Promote the proved topology of the issue to the topology snapshot generation and release it';

    public function handle(TopologySnapshotPromoter $promoter): int
    {
        try {
            $request = $this->request();
            $plan = ProofPlanFile::currentOrRetained($request, $this->option('plan'));
            $result = $promoter->promote($request, $plan->plan);
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
}
