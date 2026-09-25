<?php

declare(strict_types=1);

namespace App\Support\Logs;

/** Where a log follow writes what it learns. The command renders it as human text or JSON lines. */
interface LogFollowOutput
{
    /**
     * New lines, already redacted, oldest first, with the lines dropped and bytes skipped before them.
     *
     * @param  list<string>  $lines
     */
    public function lines(array $lines, int $dropped, int $skipped): void;

    /**
     * The follow switched to polling the one-shot read: $code is `logs.live_unavailable` or
     * `logs.stream_limit`, with $reason when one is known.
     */
    public function polling(string $code, ?string $reason): void;

    /** The realtime socket dropped; the follow reconnects and reopens its stream. */
    public function reconnecting(): void;
}
