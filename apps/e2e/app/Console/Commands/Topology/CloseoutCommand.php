<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\DeliveryFlow;
use App\E2E\ProofCloseoutService;
use InvalidArgumentException;
use Throwable;

final class CloseoutCommand extends E2ECommand
{
    #[\Override]
    protected $signature =
        'topology:closeout {issue} '
        .self::WORKTREE_OPTION
        .' {--candidate= : Exact accepted candidate commit}'
        .' {--artifact= : Exact candidate-bound artifact commit}'
        .' {--merge= : Exact verified merge commit}'
        .' {--main-sha= : Exact current main commit containing the merge}'
        .' {--json}';

    #[\Override]
    protected $description = 'Refresh from a verified merge and release its retained proof topology';

    public function handle(ProofCloseoutService $closeout): int
    {
        try {
            $request = $this->request();
            DeliveryFlow::requireProof($request->worktree);
            $candidate = $this->exactSha('candidate');
            $artifact = $this->exactSha('artifact');
            $merge = $this->exactSha('merge');
            $mainSha = $this->exactSha('main-sha');
            $record = $closeout->closeout($request, $candidate, $artifact, $merge, $mainSha);
            $this->log(
                $request,
                "candidate={$candidate} artifact={$artifact} merge={$merge} main={$mainSha} status={$record->status}",
            );
            $this->outputJson($record->toArray(), 'closeout '.$record->status);

            return $record->state === 'complete' ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            if (isset($request)) {
                $this->log($request, 'failed: '.$exception->getMessage());
            }
            $this->outputFailure($exception);

            return self::FAILURE;
        }
    }

    private function exactSha(string $option): string
    {
        $value = $this->option($option);
        if (! is_string($value) || preg_match('/\A[a-f0-9]{40}\z/D', $value) !== 1) {
            throw new InvalidArgumentException("The exact --{$option} SHA is required.");
        }

        return $value;
    }
}
