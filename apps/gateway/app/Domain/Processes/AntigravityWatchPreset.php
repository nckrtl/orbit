<?php

declare(strict_types=1);

namespace App\Domain\Processes;

final readonly class AntigravityWatchPreset
{
    public const string NAME = 'antigravity-watch';

    public const string PROMPT = 'Call agentation_watch_annotations in a loop. For each annotation: acknowledge it, apply the requested UI/frontend change only (do not run Pest, artisan test, or other test commands), then resolve it with a summary. Continue watching until this process is stopped.';

    /** @return list<string> */
    public static function command(): array
    {
        return ['/usr/local/bin/agy', '--dangerously-skip-permissions', '-p', self::PROMPT];
    }
}
