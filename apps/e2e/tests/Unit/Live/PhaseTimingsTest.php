<?php

declare(strict_types=1);

use Tests\Live\Support\PhaseTimings;

it('summarizes wall-clock phases in recorded order', function (): void {
    $timings = new PhaseTimings;
    $timings->record('acquire discovery', 12.3456);
    $timings->record('release discovery', 3.2);

    expect($timings->summary())
        ->toBe('lifecycle timings: acquire discovery=12.346s release discovery=3.200s');
});

it('reports every phase as an array for machine use', function (): void {
    $timings = new PhaseTimings;
    $timings->record('exec', 0.5);

    expect($timings->toArray())->toBe(['exec' => 0.5]);
});
