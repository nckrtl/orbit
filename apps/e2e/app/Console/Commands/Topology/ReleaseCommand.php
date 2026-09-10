<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\TopologyReleaser;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\LeaseTargetRecovery;
use App\E2E\Value\ProofReleaseReason;
use InvalidArgumentException;
use Throwable;

final class ReleaseCommand extends E2ECommand
{
    #[\Override]
    protected $signature =
        'topology:release {issue} '
        .self::WORKTREE_OPTION
        .' {--proof : Release the retained proof topology instead of discovery}'
        .' {--candidate : Release the candidate-convergence topology instead of discovery}'
        .' {--replace : Release a captured successful proof before its fresh replacement}'
        .' {--abandon : Release a captured successful proof because the issue is abandoned}'
        .' {--recover-extension= : Recover a legacy lease target as none or app-prod}'
        .' {--expected-attempt= : Full attempt ID required with --recover-extension}'
        .' {--json}';

    #[\Override]
    protected $description = 'Release discovery, or explicitly the retained proof, and sweep orphaned networks';

    public function handle(TopologyReleaser $releaser): int
    {
        try {
            $request = $this->request();
            if ($this->option('proof') && $this->option('candidate')) {
                throw new InvalidArgumentException('Select only one of --proof or --candidate.');
            }
            if ($this->option('replace') && $this->option('abandon')) {
                throw new InvalidArgumentException('Select only one of --replace or --abandon.');
            }
            if (($this->option('replace') || $this->option('abandon')) && ! $this->option('proof')) {
                throw new InvalidArgumentException('--replace and --abandon require --proof.');
            }
            $purpose = match (true) {
                (bool) $this->option('proof') => AttemptPurpose::Proof,
                (bool) $this->option('candidate') => AttemptPurpose::CandidateConvergence,
                default => AttemptPurpose::Discovery,
            };
            $recovery = LeaseTargetRecovery::fromOptions(
                $this->option('recover-extension'),
                $this->option('expected-attempt'),
            );
            $reason = match (true) {
                (bool) $this->option('replace') => ProofReleaseReason::Replacement,
                (bool) $this->option('abandon') => ProofReleaseReason::Abandonment,
                default => null,
            };
            $result = $releaser->release($request, $purpose, $recovery, $reason);
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
