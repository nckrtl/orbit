<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\TopologyReleaser;
use App\E2E\Value\AttemptPurpose;
use Throwable;

final class ReleaseCommand extends E2ECommand
{
    #[\Override]
    protected $signature =
        'topology:release {issue} '
            .self::WORKTREE_OPTION
            .' {--proof : Release the retained proof topology instead of discovery}'
            .' {--candidate : Release the candidate-convergence topology instead of discovery} {--json}';
    #[\Override]
    protected $description = 'Release discovery, or explicitly the retained proof, and sweep orphaned networks';

    public function handle(TopologyReleaser $releaser): int
    {
        try {
            $request = $this->request();
            if ($this->option('proof') && $this->option('candidate')) {
                throw new \InvalidArgumentException('Select only one of --proof or --candidate.');
            }
            $purpose = match (true) {
                (bool) $this->option('proof') => AttemptPurpose::Proof,
                (bool) $this->option('candidate') => AttemptPurpose::CandidateConvergence,
                default => null,
            };
            $result = $releaser->release($request, $purpose);
            $this->log($request, 'purpose='.$result['purpose'].' attempt='.$result['attempt_id'].' ok');
            $this->outputJson($result, 'released '.$result['attempt_id']);

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
