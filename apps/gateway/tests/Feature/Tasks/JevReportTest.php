<?php

declare(strict_types=1);

use App\Models\JevDecision;
use Illuminate\Support\Facades\Artisan;

it('reports Jev accuracy, calibration, failures, and latency as JSON', function (): void {
    JevDecision::query()->create([
        'purpose' => 'brief_coverage',
        'questions' => ['subtask_1' => []],
        'input_state' => [],
        'answers' => ['subtask_1' => ['value' => true, 'selected_answer_probability' => 0.97]],
        'labels' => ['questions' => ['subtask_1' => ['label' => 'correct']]],
        'latency_ms' => 10,
    ]);
    JevDecision::query()->create([
        'purpose' => 'brief_coverage',
        'questions' => ['subtask_2' => []],
        'input_state' => [],
        'answers' => ['subtask_2' => ['value' => false, 'selected_answer_probability' => 0.8]],
        'labels' => ['questions' => ['subtask_2' => ['label' => 'false_negative']]],
        'latency_ms' => 30,
    ]);
    JevDecision::query()->create([
        'purpose' => 'brief_coverage',
        'questions' => ['subtask_3' => []],
        'input_state' => [],
        'answers' => ['subtask_3' => ['value' => true, 'selected_answer_probability' => 0.8]],
        'labels' => ['questions' => ['subtask_3' => ['label' => 'false_positive']]],
        'latency_ms' => 40,
    ]);
    JevDecision::query()->create([
        'purpose' => 'brief_coverage',
        'questions' => [],
        'input_state' => [],
        'answers' => null,
        'labels' => ['call' => ['label' => 'correct']],
        'latency_ms' => 20,
    ]);
    JevDecision::query()->create([
        'purpose' => 'brief_coverage',
        'questions' => [],
        'input_state' => [],
        'answers' => null,
        'error_code' => 'provider_error',
        'latency_ms' => 30,
    ]);
    JevDecision::query()->create([
        'purpose' => 'unknown_purpose',
        'questions' => [],
        'input_state' => [],
        'answers' => null,
    ]);

    Artisan::call('orbit:tasks:jev-report', ['--json' => true]);
    $allReport = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $report = $allReport['brief_coverage'];

    expect($report['calls'])->toBe(5)
        ->and($report['failures'])->toBe(1)
        ->and($report['labeled_share'])->toBe(4 / 5)
        ->and($report['accuracy'])->toBe(1 / 3)
        ->and($report['false_negatives'])->toBe(1)
        ->and($report['false_positives'])->toBe(1)
        ->and($report['call_labels'])->toBe(['correct' => 1])
        ->and($report['calibration']['[.9,1]'])->toBe(['count' => 1, 'accuracy' => 1])
        ->and($report['calibration']['[.8,.9)'])->toBe(['count' => 2, 'accuracy' => 0])
        ->and($report['latency_ms'])->toBe(['p50' => 30, 'p95' => 40])
        ->and($allReport['unknown_purpose']['labeled_share'])->toBe(0)
        ->and($allReport['unknown_purpose']['accuracy'])->toBeNull()
        ->and($allReport['unknown_purpose']['calibration']['[.9,1]'])->toBe(['count' => 0, 'accuracy' => null])
        ->and($allReport['unknown_purpose']['latency_ms'])->toBe(['p50' => null, 'p95' => null]);

    Artisan::call('orbit:tasks:jev-report');
    $human = Artisan::output();
    expect($human)->toContain('labeled share 80%')
        ->and($human)->toContain('false positives 1; false negatives 1')
        ->and($human)->toContain('call labels: correct 1')
        ->and($human)->toContain('calibration [0,.5): 0 questions, accuracy n/a')
        ->and($human)->toContain('latency ms: p50 30, p95 40')
        ->and($human)->toContain('unknown_purpose: 1 calls; 0 failures; labeled share 0%')
        ->and($human)->toContain('latency ms: p50 n/a, p95 n/a');
});
