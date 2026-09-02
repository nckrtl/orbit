<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\TopologyProofRunner;
use Throwable;

final class CandidateCommand extends E2ECommand
{
    #[\Override]
    protected $signature = 'topology:candidate {issue} '.self::WORKTREE_OPTION.' {--json}';

    #[\Override]
    protected $description = 'Converge and verify an equivalent accepted candidate without rerunning feature actions';

    public function handle(TopologyProofRunner $runner): int
    {
        try {
            $request = $this->request();
            $result = $runner->convergeCandidate($request);
            $status = $result['status'] ?? null;
            $attempt = $result['attempt_id'] ?? null;
            if (! is_string($status) || ! is_string($attempt)) {
                throw new \RuntimeException('Candidate-convergence output is invalid.');
            }
            $this->log($request, 'candidate attempt='.$attempt.' '.$status);
            $this->outputJson($result, $status.' '.$attempt);

            return $status === 'converged' ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            if (isset($request)) {
                $this->log($request, 'failed: '.$exception->getMessage());
            }
            $this->outputFailure($exception);

            return self::FAILURE;
        }
    }
}
