<?php

declare(strict_types=1);

namespace App\Services\SelfUpdate;

use Illuminate\Support\Facades\Process;
use Orbit\Sdk\Responses\Gateway\DesiredFleetStateResponse;
use Orbit\Sdk\Responses\Gateway\FleetReleaseAssetResponse;

/**
 * The two steps of `orbit self-update` (ADR 0202): replace this CLI with the Gateway's CLI release, and on a
 * managed Linux Node replace `orbit-agent` with the Gateway's pin. Each step reports a verified outcome and
 * never throws.
 */
final readonly class SelfUpdater
{
    public const string AgentService = 'orbit-agent.service';

    private const string ReleaseVersion = '/\A0\.([1-9][0-9]{0,9})\.0\z/D';

    public function __construct(
        private SelfUpdateHost $host,
        private BinaryInstaller $installer,
        private ReleaseDownloader $downloader,
    ) {}

    public function updateCli(DesiredFleetStateResponse $state, string $currentVersion, bool $allowDowngrade): SelfUpdateStep
    {
        $release = $state->cli;
        $binary = $this->host->runningBinary();

        if ($binary->kind === RunningBinaryKind::Source || $binary->path === null) {
            return new SelfUpdateStep('cli', SelfUpdateOutcome::Skipped, reason: 'source_checkout');
        }

        if ($binary->kind === RunningBinaryKind::Phar) {
            return new SelfUpdateStep('cli', SelfUpdateOutcome::Skipped, $binary->path, reason: 'not_a_release_binary');
        }

        if ($release->isPending()) {
            return new SelfUpdateStep(
                'cli',
                SelfUpdateOutcome::Pending,
                $binary->path,
                new InstalledVersion($currentVersion, $this->sha256($binary->path)),
                new InstalledVersion($release->version, null),
                reason: $release->reason ?? 'release_missing',
            );
        }

        if (! $release->isAvailable() || $release->version === null || $release->checksumsUrl === null) {
            return new SelfUpdateStep('cli', SelfUpdateOutcome::Skipped, $binary->path, reason: $release->reason ?? 'release_unavailable');
        }

        $platform = $this->host->platform();
        $asset = $platform === null ? null : $release->asset($platform);

        if ($asset === null) {
            return new SelfUpdateStep('cli', SelfUpdateOutcome::Skipped, $binary->path, reason: 'platform_unsupported');
        }

        $path = $binary->path;
        $installed = $this->sha256($path);
        $before = new InstalledVersion($currentVersion, $installed);
        $after = new InstalledVersion($release->version, $asset->sha256);

        if ($installed === $asset->sha256) {
            return new SelfUpdateStep('cli', SelfUpdateOutcome::Unchanged, $path, $before, $after);
        }

        try {
            $this->guardVersion($currentVersion, $release->version, $allowDowngrade);
            $this->confirmChecksumFile($release->checksumsUrl, $asset, 'self_update');
            $this->installer->install(
                target: $path,
                url: $asset->url,
                sha256: $asset->sha256,
                errorPrefix: 'self_update',
                mode: $this->mode($path),
                owner: $this->host->isRoot() ? $this->owner($path) : null,
                verify: fn (string $candidate) => $this->verifyCandidate($candidate, $release->version),
            );
        } catch (SelfUpdateFailure $failure) {
            return new SelfUpdateStep('cli', SelfUpdateOutcome::Failed, $path, $before, $before, failure: $failure);
        }

        return new SelfUpdateStep('cli', SelfUpdateOutcome::Updated, $path, $before, $after);
    }

    public function updateAgent(DesiredFleetStateResponse $state): SelfUpdateStep
    {
        $path = $this->host->agentBinaryPath();

        if (! $this->host->isManagedNode()) {
            return new SelfUpdateStep('agent', SelfUpdateOutcome::Skipped, reason: 'not_managed_node');
        }

        $platform = $this->host->platform();
        $asset = $platform === null ? null : $state->agent->asset($platform);

        if ($asset === null) {
            return new SelfUpdateStep('agent', SelfUpdateOutcome::Skipped, $path, reason: 'platform_unsupported');
        }

        $installed = $this->sha256($path);
        $before = new InstalledVersion($installed === $asset->sha256 ? $state->agent->version : null, $installed);
        $after = new InstalledVersion($state->agent->version, $asset->sha256);

        if ($installed === $asset->sha256) {
            return new SelfUpdateStep('agent', SelfUpdateOutcome::Unchanged, $path, $before, $after);
        }

        if (! $this->host->isRoot()) {
            return new SelfUpdateStep('agent', SelfUpdateOutcome::Skipped, $path, $before, $before, reason: 'root_required');
        }

        try {
            $this->installer->install(target: $path, url: $asset->url, sha256: $asset->sha256, errorPrefix: 'agent', mode: 0755, owner: $this->host->agentOwner());
        } catch (SelfUpdateFailure $failure) {
            return new SelfUpdateStep('agent', SelfUpdateOutcome::Failed, $path, $before, $before, failure: $failure);
        }

        $restart = Process::timeout(60)->run(['systemctl', 'restart', self::AgentService]);

        if (! $restart->successful()) {
            return new SelfUpdateStep('agent', SelfUpdateOutcome::Failed, $path, $before, $after, failure: new SelfUpdateFailure(
                'agent.restart_failed',
                'The new orbit-agent is in place, but systemctl could not restart '.self::AgentService.'.',
            ));
        }

        return new SelfUpdateStep('agent', SelfUpdateOutcome::Updated, $path, $before, $after);
    }

    /**
     * Release versions order by their commit count. A build that is not a release has no place in that order,
     * so replacing it needs the same consent as a downgrade.
     *
     * @throws SelfUpdateFailure
     */
    private function guardVersion(string $current, string $target, bool $allowDowngrade): void
    {
        if ($allowDowngrade) {
            return;
        }

        if (preg_match(self::ReleaseVersion, $current, $currentMatch) !== 1) {
            throw new SelfUpdateFailure('self_update.version_unknown', "This orbit reports version {$current}, which is not a release. Pass --allow-downgrade to replace it with {$target}.");
        }

        preg_match(self::ReleaseVersion, $target, $targetMatch);

        if ((int) ($targetMatch[1] ?? 0) < (int) $currentMatch[1]) {
            throw new SelfUpdateFailure('self_update.downgrade_refused', "The Gateway's CLI release {$target} is older than this orbit, {$current}. Pass --allow-downgrade to install it.");
        }
    }

    /**
     * The release's own `SHA256SUMS` must name the binary with the digest the Gateway sent.
     *
     * @throws SelfUpdateFailure
     */
    private function confirmChecksumFile(string $url, FleetReleaseAssetResponse $asset, string $errorPrefix): void
    {
        $directory = sys_get_temp_dir().'/orbit-self-update-'.bin2hex(random_bytes(8));

        if (! @mkdir($directory, 0700)) {
            throw new SelfUpdateFailure($errorPrefix.'.download_failed', 'Could not prepare a temporary directory for SHA256SUMS.');
        }

        $path = $directory.'/SHA256SUMS';

        try {
            $this->downloader->download($url, $path, $errorPrefix);
            $contents = (string) @file_get_contents($path, length: 65536);
        } finally {
            @unlink($path);
            @rmdir($directory);
        }

        foreach (explode("\n", $contents) as $line) {
            if (preg_match('/\A([0-9a-f]{64})  (\S+)\z/D', rtrim($line, "\r"), $matches) === 1 && $matches[2] === $asset->name) {
                if (hash_equals($asset->sha256, $matches[1])) {
                    return;
                }

                break;
            }
        }

        throw new SelfUpdateFailure($errorPrefix.'.checksum_mismatch', "The release SHA256SUMS does not confirm the Gateway's checksum for {$asset->name}.");
    }

    /** @throws SelfUpdateFailure */
    private function verifyCandidate(string $candidate, string $version): void
    {
        $home = sys_get_temp_dir().'/orbit-self-update-home-'.bin2hex(random_bytes(8));
        @mkdir($home, 0700);

        try {
            $result = Process::timeout(60)->env(['ORBIT_HOME' => $home])->run([$candidate, '--version']);
        } finally {
            @rmdir($home);
        }

        if (! $result->successful() || trim($result->output()) !== 'Orbit '.$version) {
            throw new SelfUpdateFailure('self_update.candidate_invalid', "The downloaded orbit does not run here or does not report version {$version}.");
        }
    }

    /**
     * The SHA-256 of a file on disk. A standalone binary reads its own path as the PHAR appended to it, not as
     * the whole file, so the digest reads through an equivalent path that the binary does not intercept.
     */
    private function sha256(string $path): ?string
    {
        $path = dirname($path).'/./'.basename($path);

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $digest = hash_file('sha256', $path);

        return is_string($digest) ? $digest : null;
    }

    private function mode(string $path): int
    {
        $permissions = @fileperms($path);

        return is_int($permissions) ? ($permissions & 0777) | 0111 : 0755;
    }

    /** @return array{int, int}|null */
    private function owner(string $path): ?array
    {
        $user = @fileowner($path);
        $group = @filegroup($path);

        return is_int($user) && is_int($group) ? [$user, $group] : null;
    }
}
