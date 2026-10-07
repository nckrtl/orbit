<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\DeployedGatewayRelease;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Models\Activity;
use App\Models\GatewayRelease;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes the release row, the Activity entry, and the pause marker. The pause marker is a file in
 * `ORBIT_HOME` so a later automatic release can see a pause even before it reads the table.
 */
final readonly class GatewayReleaseRecorder
{
    public function __construct(private ?string $orbitHome = null) {}

    public function write(DeployedGatewayRelease $release): GatewayRelease
    {
        $row = GatewayRelease::query()->create([
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
        ]);

        $this->pauseMarker($release);
        $this->activity($release);

        return $row;
    }

    /**
     * Records a refused or failed attempt that names no prepared commit, such as an unknown commit
     * or a rollback that would cross a migration. Nothing changed, so there is no release row, only
     * the failed Activity entry.
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
        $path = $this->home().'/gateway-release.paused';

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

    private function activity(DeployedGatewayRelease $release): void
    {
        try {
            Activity::query()->create([
                'log_name' => 'commands',
                'description' => 'gateway:release:'.$release->trigger,
                'event' => 'command',
                'properties' => [
                    'release' => $release->id,
                    'sha' => $release->sha,
                    'outcome' => $release->outcome,
                    'migrations_ran' => $release->migrationsRan,
                    'previous' => $release->previousId,
                    'cleanup_paused' => $release->cleanupPaused,
                    'snapshot' => $release->snapshotPath,
                    'error_code' => $release->errorCode,
                ],
                'request_id' => (string) Str::uuid(),
                'command' => 'gateway:release:'.$release->trigger,
                'status' => $release->succeeded() ? 'succeeded' : 'failed',
                'duration_ms' => $release->durationMs,
                'exit_code' => $release->succeeded() ? 0 : 1,
                'error_code' => $release->errorCode,
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
