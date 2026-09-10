<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\DeliveryFlow;
use App\E2E\ProofCaptureService;
use App\E2E\ProofPlanFile;
use Throwable;

final class CaptureCommand extends E2ECommand
{
    #[\Override]
    protected $signature =
        'topology:capture {issue} '
        .self::WORKTREE_OPTION
        .' {--plan= : The proved plan; defaults to .loop/proof/<ISSUE>.json} {--json}';

    #[\Override]
    protected $description = 'Capture complete successful-proof evidence before interactive review';

    public function handle(ProofCaptureService $capture): int
    {
        try {
            $request = $this->request();
            DeliveryFlow::requireProof($request->worktree);
            $plan = ProofPlanFile::current($request, $this->option('plan'));
            $captured = $capture->capture($request, $plan->plan);
            $this->log(
                $request,
                "attempt={$captured->attempt->value} fingerprint={$captured->fingerprint()} ok",
            );
            $this->outputJson($captured->toArray(), 'captured '.$captured->attempt->value);

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
