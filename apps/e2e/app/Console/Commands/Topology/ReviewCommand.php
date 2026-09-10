<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\DeliveryFlow;
use App\E2E\ProofReviewService;
use InvalidArgumentException;
use Throwable;

final class ReviewCommand extends E2ECommand
{
    #[\Override]
    protected $signature =
        'topology:review {issue} '
        .self::WORKTREE_OPTION
        .' {--complete= : Complete this interactive review action}'
        .' {--result= : Record the completed action as passed or failed}'
        .' {--finding= : Record an optional finding for the completed action}'
        .' {--json}';

    #[\Override]
    protected $description = 'Complete an interactive proof-review action or evaluate the current review record';

    public function handle(ProofReviewService $review): int
    {
        try {
            $request = $this->request();
            DeliveryFlow::requireProof($request->worktree);
            $action = $this->stringOption('complete');
            $result = $this->stringOption('result');
            $finding = $this->stringOption('finding');
            if (($action === null) !== ($result === null)) {
                throw new InvalidArgumentException('Use --complete and --result together.');
            }
            if ($result !== null && ! in_array($result, ['passed', 'failed'], true)) {
                throw new InvalidArgumentException('The --result value must be passed or failed.');
            }
            if ($action === null && $finding !== null) {
                throw new InvalidArgumentException('--finding requires --complete and --result.');
            }

            if ($action === null) {
                $evaluation = $review->evaluate($request);
            } else {
                assert($result !== null);
                $evaluation = $review->complete($request, $action, $result, $finding);
            }
            $this->log($request, "status={$evaluation->status} ok");
            $this->outputJson($evaluation->toArray(), 'review '.$evaluation->status);

            return $evaluation->status === 'ready' ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            if (isset($request)) {
                $this->log($request, 'failed: '.$exception->getMessage());
            }
            $this->outputFailure($exception);

            return self::FAILURE;
        }
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
