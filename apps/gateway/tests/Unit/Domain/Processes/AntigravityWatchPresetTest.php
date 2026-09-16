<?php

declare(strict_types=1);

use App\Domain\Processes\AntigravityWatchPreset;

it('pins the UI-only watch prompt, agentation_resolve, and 60m print timeout', function (): void {
    expect(AntigravityWatchPreset::command())
        ->toBe([
            '/usr/local/bin/agy',
            '--dangerously-skip-permissions',
            '--print-timeout',
            '60m',
            '-p',
            'Call agentation_watch_annotations in a loop. For each annotation: acknowledge it, apply the requested UI/frontend change only (do not run Pest, artisan test, or other test commands), then call agentation_resolve with a summary. Continue watching until this process is stopped.',
        ]);
});
