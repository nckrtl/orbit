<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\DeployedGatewayRelease;
use App\Domain\GatewayReleases\GatewayReleaseAutomation;
use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseUnitStarter;
use App\Models\Activity;
use App\Models\GatewayRelease;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes the release record, the Activity entry, and the pause marker. The record exists from the
 * start of an attempt, so a caller that follows a release sees each step as it ends. The pause
 * marker is a file in `ORBIT_HOME` so a later automatic release can see a pause even before it
 * reads the table. A finished record that needs a person raises one release alert.
 *
 * A record whose process died stays `queued` or `running`. The unit's `ExecStopPost`, every tick,
 * and every new attempt end it as `interrupted` ({@see self::settleDead()}). An interrupted release
 * that had touched the schema or the current link pauses automatic releases.
 */
final readonly class GatewayReleaseRecorder
{
    /** A queued record whose unit is not running after this long is ended as interrupted. */
    public const int QueuedStartSeconds = 120;

    public function __construct(
        private ?string $orbitHome = null,
        private ?GatewayReleaseAlerts $alerts = null,
        private ?GatewayReleaseAutomation $automation = null,
        private ?GatewayReleaseUnitStarter $units = null,
        private GatewayReleaseRetry $retry = new GatewayReleaseRetry,
    ) {}

    /**
     * Records a requested release before its unit starts. A full SHA or a release id names the
     * release at once; a short SHA is resolved by prepare.
     */
    public function queue(string $trigger, string $requested, ?string $sha, bool $force = false): GatewayRelease
    {
        return GatewayRelease::query()->create([
            'release_id' => $sha === null ? null : GatewayReleaseCommit::id($sha),
            'sha' => $sha,
            'requested' => $requested,
            'trigger' => $trigger,
            'outcome' => GatewayRelease::Queued,
            'force' => $force,
            'phases' => [],
            'duration_ms' => 0,
        ]);
    }

    /**
     * Starts an attempt under the release lock: claims a queued record, or creates a running one.
     * Holding the lock proves that no other attempt runs, so a record still `running` belongs to a
     * process that died, and it is ended as interrupted.
     */
    public function begin(string $trigger, string $requested, ?GatewayRelease $record = null, bool $force = false): ?GatewayRelease
    {
        if (! $record instanceof GatewayRelease && ! $this->hasRecords()) {
            // The first adoption can deploy the commit that creates the table. Its record is written when it ends.
            return null;
        }

        $this->settleDead($record?->id);

        if ($record instanceof GatewayRelease) {
            $record->forceFill(['outcome' => GatewayRelease::Running])->save();

            return $record;
        }

        return GatewayRelease::query()->create([
            'requested' => $requested,
            'trigger' => $trigger,
            'outcome' => GatewayRelease::Running,
            'force' => $force,
            'phases' => [],
            'duration_ms' => 0,
        ]);
    }

    private function hasRecords(): bool
    {
        try {
            return Schema::hasTable((new GatewayRelease)->getTable());
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Ends every record whose owner is gone. The caller holds the release lock, so no release
     * process runs: a `running` record is dead. A `queued` record is dead once its unit is not
     * running {@see self::QueuedStartSeconds} seconds after it was queued.
     *
     * @return list<GatewayRelease> the records it ended
     */
    public function settleDead(?int $except = null): array
    {
        $settled = [];
        $records = GatewayRelease::query()
            ->whereIn('outcome', [GatewayRelease::Running, GatewayRelease::Queued])
            ->when($except !== null, static fn ($query) => $query->whereKeyNot($except))
            ->oldest('id')
            ->get();

        foreach ($records as $record) {
            if ($record->outcome === GatewayRelease::Queued) {
                $young = $record->created_at !== null && $record->created_at->gt(Carbon::now()->subSeconds(self::QueuedStartSeconds));

                if ($young || $this->units?->isActive($record->id) === true) {
                    continue;
                }
            }

            $this->interrupt($record, $record->outcome === GatewayRelease::Queued
                ? 'The release unit did not run the queued release.'
                : 'The release process ended before it recorded an outcome.');
            $settled[] = $record;
        }

        return $settled;
    }

    /**
     * Ends a record whose process has exited, from the unit's `ExecStopPost`. A record that already
     * has an outcome is left alone.
     */
    public function settle(GatewayRelease $record): bool
    {
        $record->refresh();

        if ($record->finished()) {
            return false;
        }

        $this->interrupt($record, 'The release process ended before it recorded an outcome.');

        return true;
    }

    /** Whether a dead release had touched the schema or the current link, so it may not be retried blindly. */
    public static function touchedLiveState(GatewayRelease $record): bool
    {
        $phases = $record->phases;
        $snapshot = is_array($phases['snapshot'] ?? null) ? ($phases['snapshot']['outcome'] ?? null) : null;
        $migrate = is_array($phases['migrate'] ?? null) ? ($phases['migrate']['outcome'] ?? null) : null;

        return $snapshot === 'snapshotted' || ($migrate !== null && $migrate !== 'skipped') || array_key_exists('switch', $phases);
    }

    private function interrupt(GatewayRelease $record, string $message): void
    {
        $record->forceFill([
            'outcome' => GatewayRelease::Interrupted,
            'retryable' => $record->commit() === null || $this->retry->retryable('gateway.release_interrupted', (string) $record->commit(), $record->id),
            'error_code' => 'gateway.release_interrupted',
            'message' => $message,
        ])->save();
        $this->activity($record->trigger, GatewayRelease::Interrupted, $record->duration_ms, 'gateway.release_interrupted', [
            'release' => $record->release_id,
            'sha' => $record->sha,
            'outcome' => GatewayRelease::Interrupted,
            'error_code' => 'gateway.release_interrupted',
        ]);

        if (self::touchedLiveState($record)) {
            $this->automation?->pauseFor('interrupted', $record);
        }

        $this->alerts?->raise($record);
    }

    /**
     * Stores the steps that ended so far. A failed write never stops the release.
     *
     * @param  array<string, mixed>  $phases
     */
    public function progress(?GatewayRelease $record, array $phases, ?string $sha = null): void
    {
        if (! $record instanceof GatewayRelease) {
            return;
        }

        try {
            $record->forceFill([
                'phases' => $phases,
                ...($sha !== null ? ['sha' => $sha, 'release_id' => GatewayReleaseCommit::id($sha)] : []),
            ])->save();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function write(DeployedGatewayRelease $release, ?GatewayRelease $record = null): GatewayRelease
    {
        $attributes = [
            'release_id' => $release->id,
            'sha' => $release->sha,
            'trigger' => $release->trigger,
            'outcome' => $release->outcome,
            'migrations_ran' => $release->migrationsRan,
            'retryable' => $release->retryable,
            'cleanup_paused' => $release->cleanupPaused,
            'snapshot_path' => $release->snapshotPath,
            'previous_release_id' => $release->previousId,
            'phases' => $release->phases,
            'error_code' => $release->errorCode,
            'message' => $release->message,
            'duration_ms' => $release->durationMs,
        ];

        if ($record instanceof GatewayRelease) {
            $record->forceFill($attributes)->save();
            $row = $record;
        } else {
            $row = GatewayRelease::query()->create($attributes);
        }

        $this->pauseMarker($release);
        $this->pauseState($release, $row);
        $this->activity($release->trigger, $release->outcome, $release->durationMs, $release->errorCode, [
            'release' => $release->id,
            'sha' => $release->sha,
            'outcome' => $release->outcome,
            'migrations_ran' => $release->migrationsRan,
            'previous' => $release->previousId,
            'cleanup_paused' => $release->cleanupPaused,
            'snapshot' => $release->snapshotPath,
            'error_code' => $release->errorCode,
        ]);
        $this->alerts?->raise($row);

        return $row;
    }

    /**
     * Ends an attempt that stopped before it wrote an outcome, such as a refused commit, a busy
     * lock, or an unexpected error. A record that already has an outcome is left alone. `$phase`
     * names the release step that failed; it defaults to the exception's step.
     */
    public function fail(?GatewayRelease $record, Throwable $exception, bool $retryable, int $durationMs, ?string $phase = null): void
    {
        if (! $record instanceof GatewayRelease) {
            if ($exception instanceof GatewayReleaseException) {
                $this->refused('deploy', $exception, $durationMs);
            }

            return;
        }

        $record->refresh();

        if ($record->finished()) {
            return;
        }

        $errorCode = $exception instanceof GatewayReleaseException ? $exception->errorCode : 'gateway.release_failed';
        $step = $phase ?? ($exception instanceof GatewayReleaseException ? $exception->step : 'release');
        $sha = $exception instanceof GatewayReleaseException && $exception->sha !== null && GatewayReleaseCommit::isSha($exception->sha)
            ? $exception->sha
            : $record->sha;
        $message = $exception instanceof GatewayReleaseException ? $exception->getMessage() : 'The release stopped with an unexpected error.';

        $record->forceFill([
            'outcome' => 'failed',
            'retryable' => $retryable,
            'sha' => $sha,
            'release_id' => $sha === null ? $record->release_id : GatewayReleaseCommit::id($sha),
            'phases' => [...$record->phases, $step => ['outcome' => 'failed', 'error_code' => $errorCode]],
            'error_code' => $errorCode,
            'message' => $message,
            'duration_ms' => $durationMs,
        ])->save();

        if (! $exception instanceof GatewayReleaseException) {
            report($exception);
        }

        $this->activity($record->trigger, 'failed', $durationMs, $errorCode, [
            'release' => $record->release_id,
            'sha' => $record->sha,
            'outcome' => 'failed',
            'error_code' => $errorCode,
        ]);
        $this->alerts?->raise($record);
    }

    /**
     * Records a refused step outside a release attempt, such as `gateway:release:adopt` on a checkout
     * with local changes. Nothing changed, so there is no release row, only the failed Activity entry.
     */
    public function refused(string $trigger, GatewayReleaseException $exception, int $durationMs): void
    {
        try {
            Activity::query()->create([
                'log_name' => 'commands',
                'description' => 'gateway:release:'.$trigger,
                'event' => 'command',
                'properties' => [
                    'outcome' => 'refused',
                    'step' => $exception->step,
                    'sha' => $exception->sha,
                    'error_code' => $exception->errorCode,
                ],
                'request_id' => (string) Str::uuid(),
                'command' => 'gateway:release:'.$trigger,
                'status' => 'failed',
                'duration_ms' => $durationMs,
                'exit_code' => in_array($exception->status, [404, 422], true) ? 2 : 1,
                'error_code' => $exception->errorCode,
            ]);
        } catch (Throwable) {
            // The command output still carries the refusal.
        }
    }

    /**
     * A failure after migrations and a verified rollback pause automatic releases. Any other verified
     * release ends a pause; a `resumed` adoption never does, because it serves an unverified release; the runner pauses again when a manual release is not the newest
     * green commit.
     */
    private function pauseState(DeployedGatewayRelease $release, GatewayRelease $row): void
    {
        if (! $this->automation instanceof GatewayReleaseAutomation) {
            return;
        }

        try {
            match (true) {
                $release->outcome === 'paused' => $this->automation->pauseFor('migration_failure', $row),
                $release->outcome === 'verified' && $release->trigger === 'rollback' => $this->pauseAfterRollback($row),
                $release->outcome === 'verified' => $this->automation->clearPause(),
                default => null,
            };
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function pauseAfterRollback(GatewayRelease $row): void
    {
        $this->automation?->pauseFor('rollback', $row);
        $this->alerts?->paused($row, 'rollback');
    }

    /**
     * The snapshot the pause marker names for this commit: the state before its first migration attempt. A retry of a
     * paused commit snapshots a database its failed migration already changed, so that copy is not the clean one.
     */
    public function pausedSnapshot(string $sha): ?string
    {
        $marker = @file_get_contents($this->home().'/gateway-release.paused');
        $decoded = is_string($marker) ? json_decode($marker, true) : null;

        if (! is_array($decoded) || ($decoded['sha'] ?? null) !== $sha || ! is_string($decoded['snapshot'] ?? null)) {
            return null;
        }

        return is_file($decoded['snapshot']) ? $decoded['snapshot'] : null;
    }

    private function pauseMarker(DeployedGatewayRelease $release): void
    {
        $path = $this->pauseMarkerPath();

        if ($release->outcome !== 'paused') {
            // Only a verified release clears a pause: `resumed` checked serving, not the schema the pause is about.
            if ($release->outcome === 'verified' && is_file($path)) {
                @unlink($path);
            }

            return;
        }

        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            return;
        }

        $body = json_encode([
            'release' => $release->id,
            'sha' => $release->sha,
            'error_code' => $release->errorCode,
            'snapshot' => $release->snapshotPath,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        @file_put_contents($path, $body."\n");
        @chmod($path, 0600);
    }

    public function pauseMarkerPath(): string
    {
        return $this->home().'/gateway-release.paused';
    }

    /** @param array<string, mixed> $properties */
    private function activity(string $trigger, string $outcome, int $durationMs, ?string $errorCode, array $properties): void
    {
        $succeeded = $outcome === 'verified';

        try {
            Activity::query()->create([
                'log_name' => 'commands',
                'description' => 'gateway:release:'.$trigger,
                'event' => 'command',
                'properties' => $properties,
                'request_id' => (string) Str::uuid(),
                'command' => 'gateway:release:'.$trigger,
                'status' => $succeeded ? 'succeeded' : 'failed',
                'duration_ms' => $durationMs,
                'exit_code' => $succeeded ? 0 : 1,
                'error_code' => $errorCode,
            ]);
        } catch (Throwable) {
            // The release row is the durable record. A failed Activity write must not hide the outcome.
        }
    }

    private function home(): string
    {
        if ($this->orbitHome !== null) {
            return rtrim($this->orbitHome, '/');
        }

        return rtrim(Config::string('orbit.home'), '/');
    }
}
