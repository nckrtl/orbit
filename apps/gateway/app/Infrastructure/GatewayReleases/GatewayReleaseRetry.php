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

    /**
     * The triggers whose attempts spend the budget. A rollback to the commit never does.
     *
     * @var list<string>
     */
    private const array Attempting = ['deploy', 'auto', 'adopt'];

    /**
     * The finished outcomes that spend the budget. A verified, queued, or running record never does.
     *
     * @var list<string>
     */
    private const array FailedAttempts = ['failed', 'switched_back', GatewayRelease::Interrupted];

    public function __construct(private int $attempts = self::Attempts) {}

    /** @param int|null $except the record of the attempt that asks, which is not an earlier attempt */
    public function retryable(string $errorCode, string $sha, ?int $except = null): bool
    {
        if (in_array($errorCode, self::Final, true)) {
            return false;
        }

        try {
            $earlier = GatewayRelease::query()
                ->where(static fn ($query) => $query->where('sha', $sha)->orWhere(static fn ($inner) => $inner->whereNull('sha')->where('requested', $sha)))
                ->whereIn('trigger', self::Attempting)
                ->whereIn('outcome', self::FailedAttempts)
                ->when($except !== null, static fn ($query) => $query->whereKeyNot($except))
                ->count();
        } catch (Throwable) {
            // The record table may not exist yet, before the first release migrated it.
            $earlier = 0;
        }

        return $earlier + 1 < $this->attempts;
    }
}
