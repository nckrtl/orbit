<?php

declare(strict_types=1);

namespace App\Domain\Logs;

use App\Infrastructure\Logs\CacheLogStreamStore;

/**
 * The open live log streams (ADR 0153). PHP-FPM workers open, renew, and close streams; the log relay
 * runs of the agent view publisher read them to relay lines and end streams whose lease ran out.
 *
 * @see CacheLogStreamStore
 */
interface LogStreamStore
{
    public const int LeaseSeconds = 60;

    public const int RenewSeconds = 20;

    public const int MaxPerNode = 16;

    /** Stores a new stream, or returns false when its Node already has `MaxPerNode` open streams. */
    public function open(LogStream $stream): bool;

    /** An open stream whose lease has not ended, or null. */
    public function find(string $id): ?LogStream;

    /** Extends an open stream's lease, activates it, and returns it, or null when it is gone. */
    public function renew(string $id, float $expiresAt): ?LogStream;

    /** Removes a stream and returns it, or null when it was already gone. */
    public function close(string $id): ?LogStream;

    /**
     * Every open stream whose lease has not ended, oldest first.
     *
     * @return list<LogStream>
     */
    public function all(): array;

    /**
     * The active open streams whose source is on one Node, at most `MaxPerNode`.
     *
     * @return list<LogStream>
     */
    public function forNode(int $nodeId): array;

    /**
     * Removes and returns every stream whose lease ended.
     *
     * @return list<LogStream>
     */
    public function sweep(): array;

    /** How far the relay got with a stream, or null before its first `log.lines` event. */
    public function cursor(string $id): ?LogRelayCursor;

    /** Records how far the relay got with an open stream. Closing or sweeping the stream removes it. */
    public function saveCursor(string $id, LogRelayCursor $cursor): void;
}
