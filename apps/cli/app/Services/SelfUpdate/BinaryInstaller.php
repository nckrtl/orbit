<?php

declare(strict_types=1);

namespace App\Services\SelfUpdate;

use Closure;
use Throwable;

/**
 * Replaces a binary the way the Gateway's agent converge does: download a candidate next to the target, check
 * its SHA-256, set its owner and mode, and move it into place in one rename. Until the rename the target is
 * untouched, so a failure or an interruption at any earlier point leaves the old binary working.
 *
 * Each candidate has its own exclusive name. The caller holds the self-update lock, so a candidate with that
 * pattern is always one that an interrupted run left behind, and it is removed first.
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
        $directory = $this->writableDirectory($target, $errorPrefix);
        $this->removeStaleCandidates($directory);
        $candidate = $this->candidate($directory, basename($target), $errorPrefix);

        try {
            $this->downloader->download($url, $candidate, ReleaseDownloader::BinaryMaxBytes, $errorPrefix);

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
            @unlink($candidate);

            throw $failure;
        } catch (Throwable) {
            @unlink($candidate);

            throw new SelfUpdateFailure($errorPrefix.'.install_failed', 'Could not install the new '.basename($target).'.');
        }

        $this->sync($directory);
    }

    /**
     * Points `$link` at a file in its own directory in one rename. A running binary keeps reading its own
     * versioned file, so a process that runs while the link changes is not affected.
     *
     * @throws SelfUpdateFailure
     */
    public function link(string $link, string $targetName, string $errorPrefix): void
    {
        $directory = $this->writableDirectory($link, $errorPrefix);
        $temporary = $directory.'/.'.basename($link).'.orbit-link-'.bin2hex(random_bytes(6));

        if (! @symlink($targetName, $temporary)) {
            throw new SelfUpdateFailure($errorPrefix.'.install_failed', 'Could not create the link to '.$targetName.'.');
        }

        if (! @rename($temporary, $link)) {
            @unlink($temporary);

            throw new SelfUpdateFailure($errorPrefix.'.install_failed', 'Could not point '.basename($link).' at '.$targetName.'.');
        }

        $this->sync($directory);
    }

    /**
     * Moves a copy of `$source` over `$target` in one rename, such as the kept previous agent back into place.
     *
     * @throws SelfUpdateFailure
     */
    public function restore(string $source, string $target, string $errorPrefix): void
    {
        $directory = $this->writableDirectory($target, $errorPrefix);
        $candidate = $this->candidate($directory, basename($target), $errorPrefix);

        if (! @copy($source, $candidate) || ! @chmod($candidate, (int) (@fileperms($source) & 0777)) || ! @rename($candidate, $target)) {
            @unlink($candidate);

            throw new SelfUpdateFailure($errorPrefix.'.install_failed', 'Could not restore the previous '.basename($target).'.');
        }

        $this->sync($directory);
    }

    /** Keeps the current binary under another name with a hard link, or a copy where a link is not possible. */
    public function keep(string $path, string $kept): bool
    {
        if (is_link($kept) || is_file($kept)) {
            @unlink($kept);
        }

        return @link($path, $kept) || @copy($path, $kept);
    }

    /** @throws SelfUpdateFailure */
    private function writableDirectory(string $path, string $errorPrefix): string
    {
        $directory = dirname($path);

        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new SelfUpdateFailure($errorPrefix.'.not_writable', "Cannot replace {$path}. Run orbit self-update as a user who may write to {$directory}, or with sudo.");
        }

        return $directory;
    }

    /**
     * Creates an empty candidate with a name no other run uses. Opening it with `x` fails when the name exists.
     *
     * @throws SelfUpdateFailure
     */
    private function candidate(string $directory, string $name, string $errorPrefix): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = $directory.'/.'.$name.self::CandidateSuffix.'-'.bin2hex(random_bytes(6));
            $handle = @fopen($candidate, 'x');

            if ($handle !== false) {
                fclose($handle);
                @chmod($candidate, 0600);

                return $candidate;
            }
        }

        throw new SelfUpdateFailure($errorPrefix.'.install_failed', "Could not create a candidate file in {$directory}.");
    }

    private function removeStaleCandidates(string $directory): void
    {
        foreach (glob($directory.'/.*'.self::CandidateSuffix.'-*', GLOB_NOSORT) ?: [] as $stale) {
            if (is_file($stale) || is_link($stale)) {
                @unlink($stale);
            }
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
