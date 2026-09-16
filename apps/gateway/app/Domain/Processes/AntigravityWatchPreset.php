<?php

declare(strict_types=1);

namespace App\Domain\Processes;

final readonly class AntigravityWatchPreset
{
    public const string NAME = 'antigravity-watch';

    public const string PROMPT = 'Call agentation_watch_annotations in a loop. For each annotation: acknowledge it, apply the requested change, then resolve it with a summary. Continue watching until this process is stopped.';

    /** @return list<string> */
    public static function command(): array
    {
        return ['/usr/local/bin/agy', '--dangerously-skip-permissions', '-p', self::PROMPT];
    }
}
