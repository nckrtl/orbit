<?php

declare(strict_types=1);

namespace App\Domain\Processes;

final readonly class AntigravityWatchPreset
{
    public const string NAME = 'antigravity-watch';

    public const string PROMPT = 'Call agentation_watch_annotations in a loop (if it returns timeout:true, call it again immediately). For each annotation: apply ONLY the requested UI/frontend change in the app source as fast as possible, then you MUST call agentation_resolve with annotationId and a one-line summary so the toolbar marker clears. Calling agentation_acknowledge is optional and only immediately before the edit. Never finish a turn while any handled annotation is still pending or acknowledged — always agentation_resolve. Do not write or run tests (no php artisan test, Pest, Browser, or other suites). Stay interactive; do not exit. Continue until this process is stopped.';

    /**
     * The watcher argv: the configured wrapper when the Gateway names one, otherwise Antigravity in
     * print mode with the watch prompt.
     *
     * @return list<string>
     */
    public static function command(?string $wrapper = null): array
    {
        if ($wrapper !== null && $wrapper !== '') {
            return [$wrapper];
        }

        return ['/usr/local/bin/agy', '--dangerously-skip-permissions', '-p', self::PROMPT];
    }
}
