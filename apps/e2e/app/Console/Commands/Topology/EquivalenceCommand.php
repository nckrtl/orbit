<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\ProofEquivalenceEvaluator;
use App\E2E\ProofPlanFile;
use App\E2E\Value\ProofEquivalenceReport;
use App\E2E\Value\ProofEquivalenceResult;
use Throwable;

final class EquivalenceCommand extends E2ECommand
{
    #[\Override]
    protected $signature =
        'topology:equivalence {issue} '
            .self::WORKTREE_OPTION
            .' {--plan= : The retained proof plan; defaults to .loop/proof/<ISSUE>.json} {--json}';
    #[\Override]
    protected $description = 'Compare the worktree HEAD with the immutable inputs of its retained proof';

    public function handle(ProofEquivalenceEvaluator $evaluator): int
    {
        try {
            $request = $this->request();
            $plan = ProofPlanFile::currentOrRetained($request, $this->option('plan'));
            $result = $evaluator->evaluate(
                $request,
                $plan->plan,
                $plan->path,
            );
            $this->log(
                $request,
                "proved={$result->provedSha} accepted={$result->acceptedSha} {$result->result->value}",
            );
            $this->outputJson($result->toArray(), $this->text($result));

            return in_array(
                $result->result,
                [
                    ProofEquivalenceResult::Exact,
                    ProofEquivalenceResult::Equivalent,
                ],
                true,
            )
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $exception) {
            if (isset($request)) {
                $this->log($request, 'failed: '.$exception->getMessage());
            }
            $this->outputFailure($exception);

            return self::FAILURE;
        }
    }

    private function text(ProofEquivalenceReport $report): string
    {
        $text =
            $report->result->value." {$report->provedSha}..{$report->acceptedSha}"." next_action={$report->nextAction}";
        if ($report->errors !== []) {
            $text .= "\n".implode("\n", $report->errors);
        }

        return $text;
    }
}
