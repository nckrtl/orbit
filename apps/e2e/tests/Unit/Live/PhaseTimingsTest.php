<?php

declare(strict_types=1);

use Tests\Live\Support\PhaseTimings;

it('summarizes wall-clock phases in recorded order', function (): void {
    $timings = new PhaseTimings;
    $timings->record('acquire discovery', 12.3456);
    $timings->record('release discovery', 3.2);

    expect($timings->summary())
        ->toBe(
            'lifecycle timings: acquire discovery=12.346s release discovery=3.200s',
        );
});

it('appends journal phase durations to the phase they belong to', function (): void {
    $timings = new PhaseTimings;
    $timings->record('prove candidate', 80.0);
    $timings->mergeJournal(
        'prove candidate',
        [
            ['event' => 'topology.prove.phases', 'duration_ms' => ['clone' => 1500.5, 'start' => 2000.0]],
            ['event' => 'other', 'duration_ms' => ['ignored' => 1.0]],
        ],
        'topology.prove.phases',
    );

    expect($timings->summary())
        ->toBe(
            'lifecycle timings: prove candidate=80.000s [clone=1501ms start=2000ms]',
        );
});

it('rejects journal durations for an unrecorded phase', function (): void {
    new PhaseTimings()->mergeJournal('missing', [], 'topology.prove.phases');
})->throws(InvalidArgumentException::class, 'missing');

it('reports every phase as an array for machine use', function (): void {
    $timings = new PhaseTimings;
    $timings->record('exec', 0.5);

    expect($timings->toArray())->toBe(['exec' => ['seconds' => 0.5, 'journal_ms' => []]]);
});
