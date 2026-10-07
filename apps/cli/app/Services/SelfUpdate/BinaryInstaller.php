<?php

declare(strict_types=1);

namespace App\Services\SelfUpdate;

use Closure;
use Throwable;

/**
 * Replaces a binary the way the Gateway's agent converge does: download a candidate next to the target, check
 * its SHA-256, set its owner and mode, and move it into place in one rename. Until the rename the target is
 * untouched, so a failure or an interruption at any earlier point leaves the old binary working. A candidate
 * that an interrupted run left behind is removed first.
 */
final readonly class BinaryInstaller
{
    public const string CandidateSuffix = '.orbit-candidate';

    public function __construct(private ReleaseDownloader $downloader) {}

    /**
     * @param  string  $errorPrefix  The error code family: `self_update` for the CLI, `agent` for the agent.
     * @param  array{int, int}|null  $owner  The user and group IDs to give the candidate, or null to keep the caller's.
     * @param  (Closure(string): void)|null  $verify  Checks the verified candidate before the rename; throwing keeps the target.
     *
     * @throws SelfUpdateFailure
     */
    public function install(
        string $target,
        string $url,
        string $sha256,
        string $errorPrefix,
        int $mode = 0755,
        ?array $owner = null,
        ?Closure $verify = null,
    ): void {
        $directory = dirname($target);
        $candidate = $target.self::CandidateSuffix;

        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new SelfUpdateFailure($errorPrefix.'.not_writable', "Cannot replace {$target}. Run orbit self-update as a user who may write to {$directory}, or with sudo.");
        }

        $this->remove($candidate);

        try {
            $this->downloader->download($url, $candidate, $errorPrefix);

            $downloaded = hash_file('sha256', $candidate);

            if (! is_string($downloaded) || ! hash_equals($sha256, $downloaded)) {
                throw new SelfUpdateFailure($errorPrefix.'.checksum_mismatch', 'The downloaded '.basename($url).' failed checksum verification.');
            }

            if ($owner !== null && (! @chown($candidate, $owner[0]) || ! @chgrp($candidate, $owner[1]))) {
                throw new SelfUpdateFailure($errorPrefix.'.install_failed', 'Could not set the owner of the new '.basename($target).'.');
            }

            if (! @chmod($candidate, $mode)) {
                throw new SelfUpdateFailure($errorPrefix.'.install_failed', 'Could not set the mode of the new '.basename($target).'.');
            }

            $this->sync($candidate);

            if ($verify instanceof Closure) {
                $verify($candidate);
            }

            if (! @rename($candidate, $target)) {
                throw new SelfUpdateFailure($errorPrefix.'.install_failed', 'Could not move the new '.basename($target).' into place.');
            }
        } catch (SelfUpdateFailure $failure) {
            $this->remove($candidate);

            throw $failure;
        } catch (Throwable) {
            $this->remove($candidate);

            throw new SelfUpdateFailure($errorPrefix.'.install_failed', 'Could not install the new '.basename($target).'.');
        }

        $this->sync($directory);
    }

    private function remove(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
        }
    }

    /** Flushes a file or a directory entry to disk, so a power loss after the rename keeps the new binary. */
    private function sync(string $path): void
    {
        $handle = @fopen($path, is_dir($path) ? 'rb' : 'r+b');

        if ($handle === false) {
            return;
        }

        @fsync($handle);
        fclose($handle);
    }
}
