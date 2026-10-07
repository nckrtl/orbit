<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Models\GatewayRelease;
use Throwable;

/**
 * Whether a failed release attempt may be tried again for the same commit. A failure that the commit itself causes
 * every time is final at once. Every other failure, such as a network error, a busy lock, a slow verify, or an error
 * the release code did not expect, gets a budget of attempts per commit, so a broken commit is not retried forever.
 */
final readonly class GatewayReleaseRetry
{
    /** Attempts per commit before a retryable failure becomes final. */
    public const int Attempts = 3;

    /** @var list<string> */
    private const array Final = [
        'gateway.release_conflict',
        'gateway.release_current_incomplete',
        'gateway.release_downgrade',
        'gateway.release_migration_crossed',
        'gateway.release_migrate_failed',
        'gateway.release_switch_back_failed',
        'gateway.release_scheduler_missing',
        'gateway.release_scheduler_mismatch',
        // CI uploads the web build before `Required checks` can pass, so a commit without one never gets it.
        'gateway.release_web_build_missing',
        'gateway.release_web_build_invalid',
        'gateway.release_smoke_missing',
    ];

    public function __construct(private int $attempts = self::Attempts) {}

    public function retryable(string $errorCode, string $sha): bool
    {
        if (in_array($errorCode, self::Final, true)) {
            return false;
        }

        try {
            $earlier = GatewayRelease::query()->where('sha', $sha)->count();
        } catch (Throwable) {
            // The record table may not exist yet, before the first release migrated it.
            $earlier = 0;
        }

        return $earlier + 1 < $this->attempts;
    }
}
