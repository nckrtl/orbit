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
    protected $description = 'Report Jev call quality, labels, calibration, and latency.';

    public function handle(): int
    {
        $decisions = DB::table('jev_decisions')->select(['purpose', 'error_code', 'latency_ms', 'labels', 'answers'])->orderBy('id')->get();
        $report = [];
        foreach ($decisions->groupBy('purpose') as $purpose => $calls) {
            $questionLabels = [];
            $callLabels = [];
            $calibration = [
                '[0,.5)' => [0, 0], '[.5,.6)' => [0, 0], '[.6,.7)' => [0, 0],
                '[.7,.8)' => [0, 0], '[.8,.9)' => [0, 0], '[.9,1]' => [0, 0],
            ];
            $latencies = [];
            $labeledCalls = 0;
            $failures = 0;
            foreach ($calls as $call) {
                $failure = $call->error_code !== null;
                $failures += (int) $failure;
                if ($call->latency_ms !== null) {
                    $latencies[] = $call->latency_ms;
                }
                $labelData = self::decode($call->labels);
                $labels = is_array($labelData['questions'] ?? null) ? $labelData['questions'] : [];
                $callData = is_array($labelData['call'] ?? null) ? $labelData['call'] : [];
                $callLabel = $callData['label'] ?? null;
                if ($labels !== [] || is_string($callLabel)) {
                    $labeledCalls++;
                }
                if (is_string($callLabel)) {
                    $callLabels[] = $callLabel;
                }
                foreach ($labels as $key => $detail) {
                    if (! is_array($detail) || ! is_string($detail['label'] ?? null)) {
                        continue;
                    }
                    $questionLabels[] = $detail['label'];
                    $answers = self::decode($call->answers);
                    $answerData = is_array($answers[$key] ?? null) ? $answers[$key] : [];
                    $probability = $answerData['selected_answer_probability'] ?? null;
                    if (! is_numeric($probability) || $probability < 0 || $probability > 1 || $detail['label'] === 'unknown') {
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
                    $calibration[$bucket][0] += (int) ($detail['label'] === 'correct');
                    $calibration[$bucket][1]++;
                }
            }
            $labeledCount = count($questionLabels);
            $calibration = array_map(static fn (array $counts): array => [
                'count' => $counts[1],
                'accuracy' => $counts[1] === 0 ? null : $counts[0] / $counts[1],
            ], $calibration);
            $report[$purpose] = [
                'calls' => $calls->count(),
                'failures' => $failures,
                'labeled_share' => $calls->isEmpty() ? null : $labeledCalls / $calls->count(),
                'accuracy' => $labeledCount === 0 ? null : count(array_filter($questionLabels, static fn (string $label): bool => $label === 'correct')) / $labeledCount,
                'false_positives' => count(array_filter($questionLabels, static fn (string $label): bool => $label === 'false_positive')),
                'false_negatives' => count(array_filter($questionLabels, static fn (string $label): bool => $label === 'false_negative')),
                'call_labels' => array_count_values($callLabels),
                'calibration' => $calibration,
                'latency_ms' => ['p50' => self::percentile($latencies, .5), 'p95' => self::percentile($latencies, .95)],
            ];
        }
        ksort($report);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            foreach ($report as $purpose => $metrics) {
                $this->line($purpose.': '.$metrics['calls'].' calls; '.$metrics['failures'].' failures; labeled share '.self::formatPercent($metrics['labeled_share']).'; question accuracy '.self::formatPercent($metrics['accuracy']).'; false positives '.$metrics['false_positives'].'; false negatives '.$metrics['false_negatives']);
                $this->line('  call labels: '.($metrics['call_labels'] === [] ? 'none' : implode(', ', array_map(static fn (string $label, int $count): string => $label.' '.$count, array_keys($metrics['call_labels']), array_values($metrics['call_labels'])))));
                foreach ($metrics['calibration'] as $bucket => $observation) {
                    $this->line('  calibration '.$bucket.': '.$observation['count'].' questions, accuracy '.self::formatPercent($observation['accuracy']));
                }
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
