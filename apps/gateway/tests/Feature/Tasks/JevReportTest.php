<?php

declare(strict_types=1);

use App\Models\JevDecision;
use Illuminate\Support\Facades\Artisan;

it('reports false negatives only with call counts and latency', function (): void {
    JevDecision::query()->create([
        'purpose' => 'brief_coverage',
        'call_started_at' => now()->toIso8601String(),
        'questions' => ['subtask_1' => []],
        'input_state' => [],
        'answers' => ['subtask_1' => ['value' => false, 'selected_answer_probability' => 0.97]],
        'labels' => ['questions' => ['subtask_1' => ['label' => 'false_negative']]],
        'latency_ms' => 10,
    ]);
    JevDecision::query()->create([
        'purpose' => 'brief_coverage',
        'call_started_at' => now()->toIso8601String(),
        'questions' => ['subtask_2' => []],
        'input_state' => [],
        'answers' => ['subtask_2' => ['value' => false, 'selected_answer_probability' => 0.8]],
        'labels' => null,
        'latency_ms' => 30,
    ]);
    JevDecision::query()->create([
        'purpose' => 'brief_coverage',
        'call_started_at' => now()->toIso8601String(),
        'questions' => [],
        'input_state' => [],
        'answers' => null,
        'labels' => ['call' => ['label' => 'correct']],
        'latency_ms' => 20,
    ]);
    JevDecision::query()->create([
        'purpose' => 'brief_coverage',
        'call_started_at' => now()->toIso8601String(),
        'questions' => [],
        'input_state' => [],
        'answers' => null,
        'error_code' => 'provider_error',
        'latency_ms' => 40,
    ]);
    JevDecision::query()->create([
        'purpose' => 'unknown_purpose',
        'call_started_at' => now()->toIso8601String(),
        'questions' => [],
        'input_state' => [],
        'answers' => null,
    ]);

    Artisan::call('orbit:tasks:jev-report', ['--json' => true]);
    $allReport = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $report = $allReport['brief_coverage'];

    expect($report)->toBe([
        'calls' => 4,
        'failures' => 1,
        'labeled_share' => 2 / 4,
        'missing_answers' => 2,
        'false_negatives' => 1,
        'false_negative_share_of_missing' => 1 / 2,
        'false_negative_confidence' => [
            '[.9,1]' => 1,
        ],
        'call_correct' => 1,
        'latency_ms' => ['p50' => 20, 'p95' => 40],
    ])
        ->and($allReport['unknown_purpose'])->toBe([
            'calls' => 1,
            'failures' => 0,
            'labeled_share' => 0,
            'missing_answers' => 0,
            'false_negatives' => 0,
            'false_negative_share_of_missing' => null,
            'false_negative_confidence' => [],
            'call_correct' => 0,
            'latency_ms' => ['p50' => null, 'p95' => null],
        ]);

    Artisan::call('orbit:tasks:jev-report');
    $human = Artisan::output();
    expect($human)->toContain('false negatives 1/2 missing answers (50%)')
        ->and($human)->toContain('call-level correct 1')
        ->and($human)->toContain('false-negative confidence [.9,1]: 1')
        ->and($human)->toContain('latency ms: p50 20, p95 40')
        ->and($human)->toContain('unknown_purpose: 1 calls; 0 failures; labeled share 0%')
        ->and($human)->toContain('latency ms: p50 n/a, p95 n/a');
});
