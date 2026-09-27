<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class JevReportCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:tasks:jev-report {--json : Print the report as JSON}';

    #[\Override]
    protected $description = 'Report Jev calls, false-negative labels, and latency.';

    public function handle(): int
    {
        $decisions = DB::table('jev_decisions')->select(['purpose', 'error_code', 'latency_ms', 'labels', 'answers'])->orderBy('id')->get();
        $report = [];
        foreach ($decisions->groupBy('purpose') as $purpose => $calls) {
            $latencies = [];
            $falseNegativeBuckets = [];
            $labeledCalls = 0;
            $failures = 0;
            $missingAnswers = 0;
            $falseNegatives = 0;
            $callCorrect = 0;

            foreach ($calls as $call) {
                $failures += (int) ($call->error_code !== null);
                if ($call->latency_ms !== null) {
                    $latencies[] = $call->latency_ms;
                }
                $labels = self::decode($call->labels);
                $questionLabels = is_array($labels['questions'] ?? null) ? $labels['questions'] : [];
                $callData = is_array($labels['call'] ?? null) ? $labels['call'] : [];
                $callLabel = $callData['label'] ?? null;
                if ($questionLabels !== [] || is_string($callLabel)) {
                    $labeledCalls++;
                }
                $callCorrect += (int) ($callLabel === 'correct');
                $answers = self::decode($call->answers);
                foreach ($answers as $key => $answer) {
                    if (! is_array($answer) || ($answer['value'] ?? null) !== false) {
                        continue;
                    }
                    $missingAnswers++;
                    $detail = $questionLabels[$key] ?? null;
                    if (! is_array($detail) || ($detail['label'] ?? null) !== 'false_negative') {
                        continue;
                    }
                    $falseNegatives++;
                    $probability = $answer['selected_answer_probability'] ?? null;
                    if (! is_numeric($probability) || $probability < 0 || $probability > 1) {
                        continue;
                    }
                    $bucket = match (true) {
                        $probability < .5 => '[0,.5)',
                        $probability < .6 => '[.5,.6)',
                        $probability < .7 => '[.6,.7)',
                        $probability < .8 => '[.7,.8)',
                        $probability < .9 => '[.8,.9)',
                        default => '[.9,1]',
                    };
                    $falseNegativeBuckets[$bucket] = ($falseNegativeBuckets[$bucket] ?? 0) + 1;
                }
            }

            $report[$purpose] = [
                'calls' => $calls->count(),
                'failures' => $failures,
                'labeled_share' => $calls->isEmpty() ? null : $labeledCalls / $calls->count(),
                'missing_answers' => $missingAnswers,
                'false_negatives' => $falseNegatives,
                'false_negative_share_of_missing' => $missingAnswers === 0 ? null : $falseNegatives / $missingAnswers,
                'false_negative_confidence' => $falseNegativeBuckets,
                'call_correct' => $callCorrect,
                'latency_ms' => ['p50' => self::percentile($latencies, .5), 'p95' => self::percentile($latencies, .95)],
            ];
        }
        ksort($report);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            foreach ($report as $purpose => $metrics) {
                $this->line($purpose.': '.$metrics['calls'].' calls; '.$metrics['failures'].' failures; labeled share '.self::formatPercent($metrics['labeled_share']));
                $this->line('  false negatives '.$metrics['false_negatives'].'/'.$metrics['missing_answers'].' missing answers ('.self::formatPercent($metrics['false_negative_share_of_missing']).')');
                foreach ($metrics['false_negative_confidence'] as $bucket => $count) {
                    $this->line('  false-negative confidence '.$bucket.': '.$count);
                }
                $this->line('  call-level correct '.$metrics['call_correct']);
                $this->line('  latency ms: p50 '.($metrics['latency_ms']['p50'] ?? 'n/a').', p95 '.($metrics['latency_ms']['p95'] ?? 'n/a'));
            }
        }

        return self::SUCCESS;
    }

    private static function formatPercent(?float $value): string
    {
        return $value === null ? 'n/a' : round($value * 100, 1).'%';
    }

    /** @return array<string, mixed> */
    private static function decode(mixed $json): array
    {
        if (! is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param list<int> $values */
    private static function percentile(array $values, float $percentile): ?int
    {
        if ($values === []) {
            return null;
        }
        sort($values);

        return $values[(int) ceil($percentile * count($values)) - 1];
    }
}
