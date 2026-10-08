<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayReleaseWebBuild;
use App\Domain\GatewayReleases\PreparedGatewayRelease;
use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Projects\ProjectSourceAccess;
use App\Infrastructure\Gateway\GatewayCheckoutAccessConverger;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use Closure;

/**
 * Builds `releases/<id>` for one commit without touching the live release. Every step is
 * idempotent: a complete release is reused, and a partial one is removed and built again.
 * `REVISION` is written last, so only a finished build counts as prepared.
 */
final readonly class GatewayReleaseBuilder
{
    /**
     * The default floor: below this much free space in the releases directory, prepare refuses
     * before it writes. `ORBIT_GATEWAY_RELEASE_MIN_FREE_MB` changes it.
     */
    public const int MinimumFreeBytes = 1_073_741_824;

    /** @var Closure(string): (float|false) */
    private Closure $freeSpace;

    /** @var Closure(string): void */
    private Closure $checkoutAccess;

    /** @var Closure(): int */
    private Closure $reservedBytes;

    /**
     * @param  (Closure(string): (float|false))|null  $freeSpace
     * @param  (Closure(string): void)|null  $checkoutAccess  Grants Caddy access to one release's Gateway application.
     * @param  int  $minimumFreeBytes  The free space prepare keeps in the releases directory.
     * @param  (Closure(): int)|null  $reservedBytes  Room prepare keeps on top of the floor, for the database snapshot a release with migrations takes.
     */
    public function __construct(
        private GatewayReleaseLayout $layout,
        private ProcessRunner $processes,
        private RepositoryReadAccess $readAccess,
        private GatewayReleaseWebBuild $web,
        private string $composer = 'composer',
        private string $php = '/usr/bin/php8.5',
        private float $composerTimeout = 900.0,
        ?Closure $freeSpace = null,
        ?Closure $checkoutAccess = null,
        private int $minimumFreeBytes = self::MinimumFreeBytes,
        ?Closure $reservedBytes = null,
        private ?string $stepLock = null,
    ) {
        $this->reservedBytes = $reservedBytes ?? static fn (): int => 0;
        $this->freeSpace = $freeSpace ?? static fn (string $path): float|false => @disk_free_space($path);
        $this->checkoutAccess = $checkoutAccess ?? static function (string $application) use ($processes): void {
            new GatewayCheckoutAccessConverger($processes, $application)->converge();
        };
    }

    /**
     * @param  bool  $withWeb  false for a release that serves no web build of its own, such as the first release of
     *                         adoption, built from a commit that may predate the CI web artifact
     */
    public function prepare(string $revision, bool $withWeb = true): PreparedGatewayRelease
    {
        $startedAt = hrtime(true);
        $revision = GatewayReleaseCommit::parse($revision);
        $this->assertLayout();
        // Before a fetch can write objects into the shared repository.
        $this->assertFreeSpace();
        $sha = $this->resolve($revision);
        $id = GatewayReleaseCommit::id($sha);
        $path = $this->layout->releasePath($id);

        if ($this->layout->preparedCommit($id) === $sha) {
            // The web build of a retained release can be gone, for example after `bin/web-deploy` pruned it.
            $this->installWeb($id, $sha);

            return new PreparedGatewayRelease($id, $sha, $path, true, $this->elapsed($startedAt));
        }

        if (is_file($path.'/REVISION')) {
            throw new GatewayReleaseException(
                step: 'worktree',
                errorCode: 'gateway.release_conflict',
                message: "Release [{$id}] already holds another commit. Remove it before preparing [{$sha}].",
            );
        }

        if ($this->layout->currentReleaseId() === $id) {
            throw new GatewayReleaseException(
                step: 'worktree',
                errorCode: 'gateway.release_current_incomplete',
                message: "The current release [{$id}] is incomplete. Repair it before preparing it again.",
            );
        }

        try {
            $this->removePartial($path);
            $this->git('worktree', 'gateway.release_worktree_failed', ['worktree', 'add', '--detach', '--force', $path, $sha]);
            $this->linkShared($path);
            $this->installDependencies($path);
            if ($withWeb) {
                $this->web->install($id, $sha);
            }

            $this->grantAccess($path);
            $this->writeRevision($path, $sha);
            $this->cacheConfiguration($path);
        } catch (GatewayReleaseException $exception) {
            throw $exception->sha === null ? $exception->withSha($sha) : $exception;
        }

        return new PreparedGatewayRelease($id, $sha, $path, false, $this->elapsed($startedAt));
    }

    /**
     * Caches a prepared release's configuration again from the shared env file, so a release that
     * goes current runs with the env file as it is now. The cache is written beside the live one
     * and renamed over it, so a request never reads a half-written configuration.
     */
    public function refreshConfiguration(string $id): void
    {
        $application = $this->layout->releaseApplicationPath($id);
        $live = $application.'/bootstrap/cache/config.php';
        $candidate = $application.'/bootstrap/cache/config.next-'.bin2hex(random_bytes(6)).'.php';
        $result = $this->processes->run(new ProcessInvocation(
            arguments: ReleaseArtisan::command($this->php, $application.'/artisan', ['config:cache', '--no-interaction'], ['APP_CONFIG_CACHE' => $candidate], $this->stepLock),
            timeout: 120.0,
        ));

        // The cached configuration holds APP_KEY. PHP-FPM runs as orbit, so nobody else needs to read it.
        if (! $result->succeeded() || ! is_file($candidate) || ! @chmod($candidate, 0o600) || ! @rename($candidate, $live)) {
            @unlink($candidate);

            throw new GatewayReleaseException(
                step: 'configuration',
                errorCode: 'gateway.release_configuration_failed',
                message: "The configuration of release [{$id}] cannot be cached again from the shared env file.",
                status: 500,
                result: $result,
            );
        }
    }

    /** Removes a retained or incomplete release that is not current, and its web build. */
    public function remove(string $id): void
    {
        if ($this->layout->currentReleaseId() === $id) {
            throw new GatewayReleaseException(
                step: 'prune',
                errorCode: 'gateway.release_current',
                message: "Release [{$id}] is current and cannot be removed.",
            );
        }

        $this->removePartial($this->layout->releasePath($id));
        $this->web->remove($id);
    }

    private function installWeb(string $id, string $sha): void
    {
        try {
            $this->web->install($id, $sha);
        } catch (GatewayReleaseException $exception) {
            throw $exception->sha === null ? $exception->withSha($sha) : $exception;
        }
    }

    private function assertLayout(): void
    {
        foreach ([$this->layout->repositoryPath() => 'repository', $this->layout->environmentPath() => 'env file'] as $path => $name) {
            if (! file_exists($path)) {
                throw new GatewayReleaseException(
                    step: 'layout',
                    errorCode: 'gateway.release_layout_missing',
                    message: "The release layout has no shared {$name} at [{$path}]. Run gateway:release:adopt first.",
                );
            }
        }

        if (! is_dir($this->layout->releasesPath()) && ! @mkdir($this->layout->releasesPath(), 0710, true) && ! is_dir($this->layout->releasesPath())) {
            throw new GatewayReleaseException(
                step: 'layout',
                errorCode: 'gateway.release_layout_missing',
                message: "The releases directory [{$this->layout->releasesPath()}] cannot be created.",
                status: 500,
            );
        }
    }

    private function resolve(string $revision): string
    {
        $sha = $this->commit($revision);

        if ($sha !== null && GatewayReleaseCommit::isSha($revision)) {
            return $sha;
        }

        $environment = $this->readEnvironment();
        $this->git('fetch', 'gateway.release_fetch_failed', ['fetch', '--no-tags', '--prune', 'origin', '+refs/heads/*:refs/remotes/origin/*'], $environment, 300.0);
        $sha = $this->commit($revision);

        if ($sha === null && GatewayReleaseCommit::isSha($revision)) {
            // A commit that no branch holds any more can still be fetched by its full SHA.
            $this->processes->run(new ProcessInvocation(
                arguments: ['git', '-C', $this->layout->repositoryPath(), 'fetch', '--no-tags', 'origin', $revision],
                timeout: 300.0,
                environment: $environment->variables,
            ));
            $sha = $this->commit($revision);
        }

        if ($sha === null) {
            throw new GatewayReleaseException(
                step: 'fetch',
                errorCode: 'gateway.release_commit_unknown',
                message: "Commit [{$revision}] is not in the repository, or the prefix names more than one commit.",
                status: 422,
            );
        }

        return $sha;
    }

    private function commit(string $revision): ?string
    {
        $result = $this->processes->run(new ProcessInvocation(
            ['git', '-C', $this->layout->repositoryPath(), 'rev-parse', '--verify', '--quiet', '--end-of-options', $revision.'^{commit}'],
            timeout: 30.0,
        ));
        $sha = trim($result->stdout);

        return $result->succeeded() && GatewayReleaseCommit::isSha($sha) && str_starts_with($sha, $revision) ? $sha : null;
    }

    private function readEnvironment(): GitReadEnvironment
    {
        $origin = $this->processes->run(new ProcessInvocation(
            ['git', '-C', $this->layout->repositoryPath(), 'remote', 'get-url', 'origin'],
            timeout: 30.0,
        ));

        if (! $origin->succeeded() || trim($origin->stdout) === '') {
            throw new GatewayReleaseException(
                step: 'fetch',
                errorCode: 'gateway.release_fetch_failed',
                message: 'The shared release repository has no origin.',
                result: $origin,
            );
        }

        return $this->readAccess->for(trim($origin->stdout), ProjectSourceAccess::GitHubApp);
    }

    private function assertFreeSpace(): void
    {
        $free = ($this->freeSpace)($this->layout->releasesPath());
        $reserved = max(0, ($this->reservedBytes)());
        $needed = $this->minimumFreeBytes + $reserved;

        if ($free !== false && $free < $needed) {
            throw new GatewayReleaseException(
                step: 'worktree',
                errorCode: 'gateway.release_disk_low',
                message: sprintf('The releases directory has %d MiB free; prepare needs at least %d MiB: the %d MiB floor plus %d MiB for a database snapshot.', (int) ($free / 1_048_576), intdiv($needed, 1_048_576), intdiv($this->minimumFreeBytes, 1_048_576), intdiv($reserved, 1_048_576)),
            );
        }
    }

    /**
     * A prepared release is read-only, so removal first restores the owner's write bit. Git forgets
     * the worktree after the directory is gone.
     */
    private function removePartial(string $path): void
    {
        if (file_exists($path) || is_link($path)) {
            $this->run('worktree', 'gateway.release_worktree_failed', ['chmod', '-R', 'u+w', '--', $path], 120.0);
            $this->run('worktree', 'gateway.release_worktree_failed', ['rm', '-rf', '--', $path], 300.0);
        }

        $this->git('worktree', 'gateway.release_worktree_failed', ['worktree', 'prune']);
    }

    /**
     * Links the shared env file and storage directory into the release. Git keeps tracking the
     * storage placeholders, so they are marked skip-worktree and the release stays clean.
     */
    private function linkShared(string $path): void
    {
        $application = $path.'/apps/gateway';
        $this->ensureSharedStorage();

        if (! @symlink($this->layout->environmentPath(), $application.'/.env')) {
            throw $this->failure('link', 'gateway.release_link_failed', "The shared env file cannot be linked into [{$application}].");
        }

        $tracked = $this->run('link', 'gateway.release_link_failed', ['git', '-C', $path, 'ls-files', '--', 'apps/gateway/storage']);
        $placeholders = array_values(array_filter(explode("\n", trim($tracked->stdout)), static fn (string $line): bool => $line !== ''));

        if ($placeholders !== []) {
            $this->run('link', 'gateway.release_link_failed', ['git', '-C', $path, 'update-index', '--skip-worktree', '--', ...$placeholders]);
        }

        $this->run('link', 'gateway.release_link_failed', ['rm', '-rf', '--', $application.'/storage']);
        $this->excludeStorageLink();

        if (! @symlink($this->layout->storagePath(), $application.'/storage')) {
            throw $this->failure('link', 'gateway.release_link_failed', "The shared storage cannot be linked into [{$application}].");
        }
    }

    private function excludeStorageLink(): void
    {
        $exclude = $this->layout->repositoryPath().'/info/exclude';
        $entry = '/apps/gateway/storage';
        $existing = is_file($exclude) ? (string) file_get_contents($exclude) : '';

        if (in_array($entry, explode("\n", $existing), true)) {
            return;
        }

        if (! is_dir(dirname($exclude))) {
            @mkdir(dirname($exclude), 0755, true);
        }

        if (@file_put_contents($exclude, rtrim($existing, "\n").($existing === '' ? '' : "\n").$entry."\n") === false) {
            throw $this->failure('link', 'gateway.release_link_failed', "The release repository exclude file [{$exclude}] cannot be written.");
        }
    }

    private function ensureSharedStorage(): void
    {
        foreach (['app/private', 'app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $directory) {
            $path = $this->layout->storagePath().'/'.$directory;

            if (! is_dir($path) && ! @mkdir($path, 0750, true) && ! is_dir($path)) {
                throw $this->failure('link', 'gateway.release_link_failed', "The shared storage directory [{$path}] cannot be created.");
            }
        }
    }

    private function installDependencies(string $path): void
    {
        foreach (['apps/cli', 'apps/gateway'] as $project) {
            $directory = $path.'/'.$project;
            $this->run('dependencies', 'gateway.release_dependencies_failed', [
                ...ReleaseArtisan::locked($this->stepLock),
                $this->composer, '--working-dir='.$directory, 'install', '--prefer-dist', '--no-interaction', '--no-progress',
            ], $this->composerTimeout);
            $this->run('dependencies', 'gateway.release_dependencies_failed', [
                $this->composer, '--working-dir='.$directory, 'check-platform-reqs', '--no-interaction',
            ], 120.0);
        }
    }

    /**
     * Gives Caddy the access to the release's `public` directory that Gateway web setup gives a
     * checkout, then removes write access from the source tree, its directories and its files, so an
     * in-place `git checkout`, `composer install`, or `composer dump-autoload` inside a release fails
     * instead of changing it.
     */
    private function grantAccess(string $path): void
    {
        try {
            ($this->checkoutAccess)($path.'/apps/gateway');
        } catch (NodeProvisioningException $exception) {
            throw new GatewayReleaseException(
                step: 'access',
                errorCode: 'gateway.release_access_failed',
                message: $exception->getMessage(),
                previous: $exception,
                result: $exception->result,
            );
        }

        $this->run('access', 'gateway.release_access_failed', [
            'find', '-P', $path,
            '(', '-path', $path.'/apps/gateway/bootstrap/cache', '-o', '-path', $path.'/apps/cli/storage', ')', '-prune',
            '-o', '(', '-type', 'd', '-o', '-type', 'f', ')', '-exec', 'chmod', 'a-w', '--', '{}', '+',
        ], 120.0);
    }

    private function writeRevision(string $path, string $sha): void
    {
        $this->run('revision', 'gateway.release_revision_failed', ['chmod', 'u+w', '--', $path]);
        $candidate = $path.'/.REVISION.'.bin2hex(random_bytes(6));
        $written = @file_put_contents($candidate, $sha."\n") !== false
            && @chmod($candidate, 0644)
            && @rename($candidate, $path.'/REVISION');
        @unlink($candidate);
        $this->run('revision', 'gateway.release_revision_failed', ['chmod', 'a-w', '--', $path]);

        if (! $written) {
            throw $this->failure('revision', 'gateway.release_revision_failed', "The REVISION file cannot be written in [{$path}].");
        }
    }

    /**
     * Caches the release's configuration from the shared env file and `REVISION`, as the Gateway
     * runs with a cached configuration. A failure removes `REVISION` again, so the release stays
     * unprepared and the next prepare builds it again.
     */
    private function cacheConfiguration(string $path): void
    {
        try {
            $this->run('configuration', 'gateway.release_configuration_failed', ReleaseArtisan::command(
                $this->php, $path.'/apps/gateway/artisan', ['config:cache', '--no-interaction'], stepLock: $this->stepLock,
            ), 120.0);

            if (! @chmod($path.'/apps/gateway/bootstrap/cache/config.php', 0o600)) {
                throw $this->failure('configuration', 'gateway.release_configuration_failed', 'The cached configuration cannot be made private.');
            }
        } catch (GatewayReleaseException $exception) {
            $this->run('configuration', 'gateway.release_configuration_failed', ['chmod', 'u+w', '--', $path]);
            @unlink($path.'/REVISION');

            throw $exception;
        }
    }

    /** @param non-empty-list<string> $arguments */
    private function git(string $step, string $errorCode, array $arguments, ?GitReadEnvironment $environment = null, float $timeout = 120.0): CommandResult
    {
        return $this->run($step, $errorCode, ['git', '-C', $this->layout->repositoryPath(), ...$arguments], $timeout, $environment);
    }

    /** @param non-empty-list<string> $arguments */
    private function run(string $step, string $errorCode, array $arguments, float $timeout = 60.0, ?GitReadEnvironment $environment = null): CommandResult
    {
        $result = $this->processes->run(new ProcessInvocation(
            arguments: $arguments,
            timeout: $timeout,
            environment: $environment === null ? [] : $environment->variables,
        ));

        if (! $result->succeeded()) {
            throw $this->failure($step, $errorCode, "Gateway release step [{$step}] failed.", $result);
        }

        return $result;
    }

    private function failure(string $step, string $errorCode, string $message, ?CommandResult $result = null): GatewayReleaseException
    {
        return new GatewayReleaseException(step: $step, errorCode: $errorCode, message: $message, status: 500, result: $result);
    }

    private function elapsed(int $startedAt): int
    {
        return intdiv(hrtime(true) - $startedAt, 1_000_000);
    }
}
