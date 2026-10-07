<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Actions\GatewayReleases\DeployGatewayReleaseAction;
use App\Domain\GatewayReleases\DeployedGatewayRelease;
use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayReleaseRuntime;
use App\Domain\GatewayReleases\GatewayReleaseVerifier;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Models\GatewayRelease;
use Closure;
use Throwable;

/**
 * Converts the in-place checkout at `/home/orbit/orbit` into the release layout, once, while it keeps serving, in two
 * phases. The first changes the layout but not the code; the second changes the code through a normal deploy.
 *
 * Phase 1, the checkout's own commit:
 *
 * 1. refuses when the checkout has tracked changes;
 * 2. creates `shared/orbit.git` as a bare clone of the checkout's own repository, with its origin, and fetches the
 *    target commit from the checkout this command runs from when the clone lacks it;
 * 3. writes `shared/gateway.env` from the checkout's `.env`, without `APP_VERSION`, keeps the original as
 *    `.env.pre-adopt`, and replaces `.env` with a link to the shared file in one rename. `.env.bak*` files are copied
 *    to `shared/env-backups/`;
 * 4. moves `apps/gateway/storage` to `shared/gateway-storage` and links it back, so the checkout keeps working;
 * 5. prepares `releases/<id>` for the checkout's own commit, so the code that serves does not change;
 * 6. swaps the checkout directory with a link to that release in one step, keeps the directory as
 *    `orbit.pre-adopt-<time>`, hands the runtime over with this process's code, and checks that the Gateway serves.
 *
 * A failure in phase 1 leaves the checkout serving with its original `.env` back in place. The storage link stays:
 * it reaches the same files.
 *
 * Phase 2, only when the target is another commit: {@see DeployGatewayReleaseAction::deploy()} releases it like any
 * deploy, with its snapshot, migrations, handoff, verify, web build, and smoke, and switches back or pauses on failure.
 *
 * Every step can run again. A rerun finishes an adoption that swapped but never verified, then deploys the target.
 */
final readonly class GatewayReleaseAdopter
{
    /** @var Closure(): string */
    private Closure $clock;

    /**
     * @param  GatewayReleaseRuntime  $runtime  runs the handoff in this process, for a release that may predate the command
     * @param  (Closure(): string)|null  $clock  UTC time for the kept directory's name
     */
    public function __construct(
        private GatewayReleaseLayout $layout,
        private ProcessRunner $processes,
        private GatewayReleaseBuilder $builder,
        private GatewayReleaseExchange $exchange,
        private GatewayReleaseRuntime $runtime,
        private GatewayReleaseVerifier $verifier,
        private GatewayReleaseRecorder $recorder,
        private GatewayReleaseGuard $guard,
        private DeployGatewayReleaseAction $deploy,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): string => gmdate('Ymd\\THis\\Z');
    }

    /**
     * @param  string|null  $commit  The commit to adopt into, as a hex SHA. Null adopts the checkout's own commit.
     * @param  string|null  $source  A checkout of that commit, such as the one this command runs from, to fetch it from.
     * @return array<string, mixed>
     */
    public function adopt(?string $commit = null, ?string $source = null): array
    {
        $target = $commit === null ? null : GatewayReleaseCommit::parse($commit);
        $layout = $this->layout->isAdopted()
            ? $this->resume((string) $this->layout->currentReleaseId())
            : $this->convert($target, $source);
        $current = (string) $this->layout->currentReleaseId();
        $sha = $target === null ? (string) $this->layout->preparedCommit($current) : $this->target($target, $source);

        // A finished adoption already on the target has nothing left to do.
        if ($layout['already'] === true && ! isset($layout['resumed']) && $sha === $this->layout->preparedCommit($current)) {
            return $layout;
        }

        // Phase 2 runs the target through the deploy path. For the checkout's own commit it installs the web build,
        // verifies the exact version, and runs smoke, which phase 1 does not.
        $deployed = $this->deploy->deploy($sha, false, 'adopt');

        return [...$layout, 'adopted' => true, 'release' => $deployed->id, 'sha' => $deployed->sha, 'deploy' => $deployed->toArray()];
    }

    /**
     * Phase 1: the layout around the checkout's own commit.
     *
     * @return array<string, mixed>
     */
    private function convert(?string $target, ?string $source): array
    {
        $startedAt = hrtime(true);
        $current = $this->layout->currentPath();
        $head = null;
        $phases = [];
        $step = 'adopt';

        try {
            $head = $this->assertCheckout($current);
            $id = GatewayReleaseCommit::id($head);
            $shared = ['repository' => $this->repository($current, $head)];

            if ($target !== null) {
                $this->target($target, $source);
            }

            $shared = [...$shared, ...$this->environment($current), ...$this->storage($current)];
            $phases['shared'] = $shared;
            $step = 'prepare';
            // Without --commit the checkout's commit is the target too, so its release gets its web build.
            $prepared = $this->builder->prepare($head, withWeb: $target === null || str_starts_with($head, $target));
            $phases['prepare'] = ['outcome' => $prepared->reused ? 'reused' : 'prepared', 'duration_ms' => $prepared->durationMs];
            $step = 'guard';
            $this->guard->assertSchema($id, false);
            $step = 'configuration';
            $this->builder->refreshConfiguration($id);
            $phases['configuration'] = ['outcome' => 'cached'];
            $step = 'switch';
            $kept = $this->layout->basePath().'/'.basename($current).'.pre-adopt-'.($this->clock)();
            $switch = $this->switch($current, $id, $kept);
            $phases['switch'] = $switch;
        } catch (Throwable $thrown) {
            $exception = GatewayReleaseException::fromThrowable($thrown, $step, $head);
            $phases[$exception->step] = ['outcome' => 'failed', 'error_code' => $exception->errorCode];
            $phases['environment'] = $this->restoreEnvironment($current);

            if ($head === null || ! isset($id) || $step === 'guard') {
                $this->recorder->refused('adopt', $exception, $this->elapsed($startedAt));
            } else {
                $this->record($this->outcome($id, $head, 'failed', $phases, $startedAt, $exception));
            }

            throw $exception;
        }

        $step = 'handoff';
        $scheduled = false;

        try {
            $phases['handoff'] = $this->runtime->handoff($id);
            $step = 'verify';
            $serving = $this->verifier->serving();
            $phases['verify'] = ['outcome' => 'passed', 'status' => $serving['status'], 'version' => $serving['version']];
            $step = 'scheduler';
            $scheduled = true;
            $phases['scheduler'] = $this->runtime->schedule($id);
        } catch (Throwable $thrown) {
            throw $this->switchBack(GatewayReleaseException::fromThrowable($thrown, $step, $head), $current, $kept, $id, $head, $phases, $startedAt, $scheduled);
        }

        $this->record($this->outcome($id, $head, 'verified', $phases, $startedAt));

        return [
            'adopted' => true,
            'already' => false,
            'from' => $head,
            'pre_adopt_path' => $kept,
            'shared' => $shared,
            'switch' => $switch,
            'phase1' => ['release' => $id, 'handoff' => $phases['handoff'], 'verify' => $phases['verify'], 'scheduler' => $phases['scheduler'], 'duration_ms' => $this->elapsed($startedAt)],
        ];
    }

    /**
     * An adopted Gateway: done when a release verified the current release; otherwise an earlier run swapped and
     * stopped before it verified, so the handoff and the serving check run again.
     *
     * @return array<string, mixed>
     */
    private function resume(string $id): array
    {
        $leftover = $this->finishLeftover();

        if ($this->verified($id)) {
            return ['adopted' => true, 'already' => true, 'release' => $id, 'pre_adopt_path' => $leftover];
        }

        $startedAt = hrtime(true);
        $sha = (string) $this->layout->preparedCommit($id);
        $phases = ['resume' => ['outcome' => 'started']];
        $step = 'handoff';

        try {
            $phases['handoff'] = $this->runtime->handoff($id);
            $step = 'verify';
            $serving = $this->verifier->serving();
            $phases['verify'] = ['outcome' => 'passed', 'status' => $serving['status'], 'version' => $serving['version']];
            $step = 'scheduler';
            $phases['scheduler'] = $this->runtime->schedule($id);
        } catch (Throwable $thrown) {
            $exception = GatewayReleaseException::fromThrowable($thrown, $step, $sha);
            $phases[$exception->step] = ['outcome' => 'failed', 'error_code' => $exception->errorCode];
            $this->record($this->outcome($id, $sha, 'failed', $phases, $startedAt, $exception));

            throw $exception;
        }

        $this->record($this->outcome($id, $sha, 'verified', $phases, $startedAt));

        return ['adopted' => true, 'already' => true, 'resumed' => true, 'release' => $id, 'pre_adopt_path' => $leftover, 'phase1' => $phases];
    }

    /** Whether a release attempt verified the current release, so adoption finished. */
    private function verified(string $id): bool
    {
        try {
            return GatewayRelease::query()->where('release_id', $id)->where('outcome', 'verified')->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The full SHA of the target commit. When the shared repository lacks it, it is fetched from the given checkout
     * if that checkout is at it. Otherwise prepare fetches it through the GitHub App.
     */
    private function target(string $target, ?string $source): string
    {
        $repository = $this->layout->repositoryPath();

        if ($source !== null && is_dir($source)) {
            $head = $this->processes->run(new ProcessInvocation(['git', '-C', $source, 'rev-parse', '--verify', '--quiet', 'HEAD^{commit}'], timeout: 30.0));

            if ($head->succeeded() && str_starts_with(trim($head->stdout), $target)) {
                $this->git($repository, ['fetch', '--quiet', '--no-tags', '--', $source, '+HEAD:refs/orbit/adopt-target'], 'gateway.release_adopt_repository_failed', null, 600.0);
            }
        }

        $resolved = $this->processes->run(new ProcessInvocation(
            ['git', '-C', $repository, 'rev-parse', '--verify', '--quiet', '--end-of-options', $target.'^{commit}'],
            timeout: 30.0,
        ));
        $sha = trim($resolved->stdout);

        return $resolved->succeeded() && GatewayReleaseCommit::isSha($sha) && str_starts_with($sha, $target) ? $sha : $target;
    }

    /**
     * @param  array<string, mixed>  $phases
     */
    private function outcome(string $id, string $sha, string $outcome, array $phases, int $startedAt, ?GatewayReleaseException $exception = null): DeployedGatewayRelease
    {
        $scheduler = $phases['scheduler'] ?? null;

        return new DeployedGatewayRelease(
            id: $id,
            sha: $sha,
            outcome: $outcome,
            trigger: 'adopt',
            migrationsRan: false,
            previousId: null,
            snapshotPath: null,
            cleanupPaused: is_array($scheduler) && ($scheduler['cleanup_paused'] ?? false) === true,
            retryable: false,
            durationMs: $this->elapsed($startedAt),
            phases: $phases,
            errorCode: $exception?->errorCode,
            message: $exception?->getMessage(),
        );
    }

    /** The checkout's commit, after refusing a path that is not a clean in-place checkout. */
    private function assertCheckout(string $current): string
    {
        if (is_link($current)) {
            throw $this->refusal('gateway.release_adopt_unsupported', "[{$current}] is a link, but not to a release in [{$this->layout->releasesPath()}].");
        }

        if (! is_dir($current.'/.git') || ! is_dir($current.'/apps/gateway')) {
            throw $this->refusal('gateway.release_adopt_not_checkout', "[{$current}] is not an in-place Orbit checkout with its own .git directory.");
        }

        $head = $this->git($current, ['rev-parse', '--verify', '--quiet', 'HEAD^{commit}'], 'gateway.release_adopt_not_checkout');
        $sha = trim($head->stdout);

        if (! GatewayReleaseCommit::isSha($sha)) {
            throw $this->refusal('gateway.release_adopt_not_checkout', "[{$current}] has no commit checked out.");
        }

        $status = $this->git($current, ['status', '--porcelain=v1', '--untracked-files=no', '--ignore-submodules=none'], 'gateway.release_adopt_not_checkout');
        $changes = array_values(array_filter(explode("\n", $status->stdout), static fn (string $line): bool => trim($line) !== ''));

        if ($changes !== []) {
            throw $this->refusal(
                'gateway.release_adopt_local_changes',
                sprintf('[%s] has %d tracked change(s), such as [%s]. Commit, move, or discard them before adoption.', $current, count($changes), trim(substr($changes[0], 3))),
            );
        }

        return $sha;
    }

    /** `shared/orbit.git`: a bare clone of the checkout's repository that keeps its origin and remote branches. */
    private function repository(string $current, string $sha): string
    {
        $repository = $this->layout->repositoryPath();
        $this->ensureDirectory($this->layout->sharedPath(), 0o750);

        if (is_dir($repository)) {
            $this->git($repository, ['cat-file', '-e', $sha.'^{commit}'], 'gateway.release_adopt_repository_conflict', "[{$repository}] exists but does not hold the checkout's commit [{$sha}].");

            return 'existing';
        }

        $partial = $repository.'.partial';
        $this->run(['rm', '-rf', '--', $partial], 'gateway.release_adopt_repository_failed');
        $this->run(['git', 'clone', '--bare', '--quiet', '--', $current, $partial], 'gateway.release_adopt_repository_failed', 600.0);
        $origin = $this->processes->run(new ProcessInvocation(['git', '-C', $current, 'remote', 'get-url', 'origin'], timeout: 30.0));

        if ($origin->succeeded() && trim($origin->stdout) !== '') {
            $this->git($partial, ['remote', 'set-url', 'origin', trim($origin->stdout)], 'gateway.release_adopt_repository_failed');
            $this->git($partial, ['fetch', '--quiet', '--no-tags', '--', $current, '+refs/remotes/origin/*:refs/remotes/origin/*'], 'gateway.release_adopt_repository_failed', null, 600.0);
        }

        // The checkout's commit may be on no branch. A ref keeps it from garbage collection.
        $this->git($partial, ['update-ref', 'refs/orbit/pre-adopt', $sha], 'gateway.release_adopt_repository_failed');

        if (! @rename($partial, $repository)) {
            throw $this->failure('gateway.release_adopt_repository_failed', "[{$partial}] cannot be renamed to [{$repository}].");
        }

        return 'created';
    }

    /**
     * `shared/gateway.env` from the checkout's `.env`, without `APP_VERSION`, so each release reports its `REVISION`.
     * The checkout's `.env` then becomes a link to it, through one rename, and the original stays as `.env.pre-adopt`.
     * The running code caches its configuration, so the switch to the link changes nothing it serves.
     *
     * @return array{env: string, env_link: string, app_version_removed: bool, env_backups: list<string>}
     */
    private function environment(string $current): array
    {
        $source = $current.'/apps/gateway/.env';
        $saved = $source.'.pre-adopt';
        $target = $this->layout->environmentPath();

        if (is_link($source)) {
            if (readlink($source) !== $target || ! is_file($saved) || is_link($saved)) {
                throw $this->refusal('gateway.release_adopt_env_conflict', "[{$source}] is a link, but not to [{$target}] with the original kept as [{$saved}].");
            }

            $original = (string) file_get_contents($saved);
            $linked = 'existing';
        } elseif (is_file($source)) {
            $original = (string) file_get_contents($source);
            $linked = 'linked';
        } else {
            throw $this->refusal('gateway.release_adopt_env_missing', "[{$source}] is not a regular file.");
        }

        $lines = preg_split('/(?<=\n)/', $original) ?: [];
        $removed = false;

        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*(export\s+)?APP_VERSION\s*=\s*\S/', $line) === 1) {
                $lines[$index] = '# '.rtrim($line, "\n")." (left out by gateway:release:adopt: each release reports its REVISION)\n";
                $removed = true;
            }
        }

        $contents = implode('', $lines);

        if (is_file($target)) {
            if ((string) file_get_contents($target) !== $contents) {
                throw $this->refusal('gateway.release_adopt_env_conflict', "[{$target}] exists and differs from [{$source}]. Compare them and remove the one that is wrong.");
            }

            $env = 'existing';
        } else {
            $this->writePrivate($target, $contents);
            $env = 'created';
        }

        $backups = [];

        foreach (glob($current.'/apps/gateway/.env.bak*') ?: [] as $backup) {
            if (! is_file($backup) || is_link($backup)) {
                continue;
            }

            $this->ensureDirectory($this->layout->sharedPath().'/env-backups', 0o700);
            $copy = $this->layout->sharedPath().'/env-backups/'.basename($backup);

            if (! is_file($copy)) {
                $this->writePrivate($copy, (string) file_get_contents($backup));
            }

            $backups[] = $copy;
        }

        if ($linked === 'linked') {
            $this->writePrivate($saved, $original);
            $next = $source.'.adopt-link';
            @unlink($next);

            if (! @symlink($target, $next) || ! @rename($next, $source)) {
                @unlink($next);

                throw $this->failure('gateway.release_adopt_env_failed', "[{$source}] cannot be replaced by a link to [{$target}].");
            }
        }

        return ['env' => $env, 'env_link' => $linked, 'app_version_removed' => $removed, 'env_backups' => $backups];
    }

    /** Puts the checkout's original `.env` back in one rename, after a failed adoption. */
    private function restoreEnvironment(string $current): string
    {
        $source = $current.'/apps/gateway/.env';
        $saved = $source.'.pre-adopt';

        if (! is_link($source) || ! is_file($saved) || is_link($saved)) {
            return 'unchanged';
        }

        return @rename($saved, $source) ? 'restored' : 'link_kept';
    }

    /**
     * Moves the checkout's storage to `shared/gateway-storage` in one rename, contents and
     * permissions as they are, and links it back so running processes keep their paths.
     */
    /** @return array{storage: string, storage_gap_us?: int} */
    private function storage(string $current): array
    {
        $source = $current.'/apps/gateway/storage';
        $target = $this->layout->storagePath();

        if (is_link($source)) {
            if (readlink($source) !== $target) {
                throw $this->refusal('gateway.release_adopt_storage_conflict', "[{$source}] links somewhere other than [{$target}].");
            }

            return ['storage' => 'existing'];
        }

        if (file_exists($target)) {
            if (is_dir($source)) {
                throw $this->refusal('gateway.release_adopt_storage_conflict', "Both [{$source}] and [{$target}] exist. Merge them by hand, then remove one.");
            }
        } else {
            if (! is_dir($source)) {
                throw $this->refusal('gateway.release_adopt_storage_missing', "[{$source}] is not a directory.");
            }

            // The tracked placeholders move with the directory, so Git must not report them as deleted.
            $tracked = $this->git($current, ['ls-files', '--', 'apps/gateway/storage'], 'gateway.release_adopt_storage_failed');
            $placeholders = array_values(array_filter(explode("\n", trim($tracked->stdout)), static fn (string $line): bool => $line !== ''));

            if ($placeholders !== []) {
                $this->git($current, ['update-index', '--skip-worktree', '--', ...$placeholders], 'gateway.release_adopt_storage_failed');
            }

            return ['storage' => 'moved', 'storage_gap_us' => $this->exchange->moveAndLink($source, $target)];
        }

        if (! @symlink($target, $source)) {
            throw $this->failure('gateway.release_adopt_storage_failed', "[{$source}] cannot be linked to [{$target}].");
        }

        return ['storage' => 'linked'];
    }

    /**
     * Puts the link in place of the checkout directory. With an atomic swap no lookup ever misses
     * the path. Without it, two renames leave it missing for the time between them, which the
     * result reports.
     *
     * @return array{method: string, gap_us: int, from: string, to: string}
     */
    private function switch(string $current, string $id, string $kept): array
    {
        $next = $current.'.adopt-next';

        if (is_link($next)) {
            @unlink($next);
        }

        if (file_exists($next) || file_exists($kept)) {
            throw $this->refusal('gateway.release_adopt_conflict', "[{$next}] or [{$kept}] already exists.");
        }

        if (! @symlink($this->layout->linkTarget($id), $next)) {
            throw $this->failure('gateway.release_switch_failed', "The release link [{$next}] cannot be created.", 'switch');
        }

        if ($this->exchange->swap($next, $current)) {
            if (! @rename($next, $kept)) {
                throw $this->failure('gateway.release_switch_failed', "The checkout was swapped, but [{$next}] cannot be renamed to [{$kept}].", 'switch');
            }

            return ['method' => 'exchange', 'gap_us' => 0, 'from' => $kept, 'to' => $this->layout->linkTarget($id)];
        }

        $started = hrtime(true);
        $moved = @rename($current, $kept);
        $linked = $moved && @rename($next, $current);
        $gap = intdiv(hrtime(true) - $started, 1_000);

        if (! $linked) {
            if ($moved) {
                @rename($kept, $current);
            }

            @unlink($next);

            throw $this->failure('gateway.release_switch_failed', "The checkout [{$current}] cannot be replaced by the release link.", 'switch');
        }

        return ['method' => 'rename', 'gap_us' => $gap, 'from' => $kept, 'to' => $this->layout->linkTarget($id)];
    }

    /**
     * Puts the checkout directory and the web build back after a failed handoff, verify, web switch, or smoke, repeats the handoff
     * from it, records the attempt, and returns the failure to throw.
     *
     * @param  array<string, mixed>  $phases
     */
    private function switchBack(GatewayReleaseException $exception, string $current, string $kept, string $id, string $sha, array $phases, int $startedAt, bool $scheduled): GatewayReleaseException
    {
        $phases[$exception->step] = ['outcome' => 'failed', 'error_code' => $exception->errorCode];
        $outcome = 'switched_back';

        try {
            if (! $this->exchange->swap($kept, $current)) {
                if (! @rename($current, $kept.'.link') || ! @rename($kept, $current)) {
                    throw $this->failure('gateway.release_switch_back_failed', "[{$kept}] cannot be put back at [{$current}].", 'switch');
                }

                @rename($kept.'.link', $kept);
            }

            @unlink($kept);
            $environment = $this->restoreEnvironment($current);
            // The checkout may predate the handoff command, so this process's code hands the runtime back to it.
            $back = ['outcome' => 'switched_back', 'to' => $current, 'environment' => $environment, 'handoff' => $this->runtime->handoff($id)];

            if ($scheduled) {
                $back['scheduler'] = $this->runtime->schedule($id);
            }

            $phases['switch_back'] = $back;
        } catch (Throwable $thrown) {
            $outcome = 'failed';
            $back = GatewayReleaseException::fromThrowable($thrown, 'switch_back', $sha);
            $phases['switch_back'] = ['outcome' => 'failed', 'error_code' => $back->errorCode];
            $exception = new GatewayReleaseException(
                step: 'switch',
                errorCode: 'gateway.release_switch_back_failed',
                message: $exception->getMessage().' Switching back to the checkout then failed: '.$back->getMessage(),
                status: 500,
                previous: $back,
                sha: $sha,
            );
        }

        $this->record($this->outcome($id, $sha, $outcome, $phases, $startedAt, $exception));

        return $exception;
    }

    /** Renames a directory a swap left at `orbit.adopt-next` when adoption stopped right after it. */
    private function finishLeftover(): ?string
    {
        $next = $this->layout->currentPath().'.adopt-next';

        if (! is_dir($next) || is_link($next)) {
            return null;
        }

        $kept = $this->layout->basePath().'/'.basename($this->layout->currentPath()).'.pre-adopt-'.($this->clock)();

        return @rename($next, $kept) ? $kept : $next;
    }

    private function record(DeployedGatewayRelease $release): void
    {
        try {
            $this->recorder->write($release);
        } catch (Throwable) {
            // The command output carries the outcome; a failed record write must not undo the adoption.
        }
    }

    private function ensureDirectory(string $path, int $mode): void
    {
        if (! is_dir($path) && ! @mkdir($path, $mode, true) && ! is_dir($path)) {
            throw $this->failure('gateway.release_adopt_repository_failed', "[{$path}] cannot be created.");
        }
    }

    private function writePrivate(string $path, string $contents): void
    {
        $candidate = $path.'.'.bin2hex(random_bytes(6));
        $umask = umask(0o077);

        try {
            $written = @file_put_contents($candidate, $contents) !== false && @chmod($candidate, 0o600) && @rename($candidate, $path);
        } finally {
            umask($umask);
        }

        if (! $written) {
            @unlink($candidate);

            throw $this->failure('gateway.release_adopt_env_failed', "[{$path}] cannot be written.");
        }
    }

    /** @param non-empty-list<string> $arguments */
    private function git(string $directory, array $arguments, string $errorCode, ?string $message = null, float $timeout = 60.0): CommandResult
    {
        $result = $this->processes->run(new ProcessInvocation(['git', '-C', $directory, ...$arguments], timeout: $timeout));

        if (! $result->succeeded()) {
            throw new GatewayReleaseException(
                step: 'adopt',
                errorCode: $errorCode,
                message: $message ?? "Git failed in [{$directory}]: ".trim($result->stderr),
                status: 409,
                result: $result,
            );
        }

        return $result;
    }

    /** @param non-empty-list<string> $arguments */
    private function run(array $arguments, string $errorCode, float $timeout = 60.0): CommandResult
    {
        $result = $this->processes->run(new ProcessInvocation($arguments, timeout: $timeout));

        if (! $result->succeeded()) {
            throw new GatewayReleaseException(
                step: 'adopt',
                errorCode: $errorCode,
                message: '['.implode(' ', array_slice($arguments, 0, 3)).'] failed: '.trim($result->stderr),
                status: 500,
                result: $result,
            );
        }

        return $result;
    }

    private function refusal(string $errorCode, string $message): GatewayReleaseException
    {
        return new GatewayReleaseException(step: 'adopt', errorCode: $errorCode, message: $message, status: 409);
    }

    private function failure(string $errorCode, string $message, string $step = 'adopt'): GatewayReleaseException
    {
        return new GatewayReleaseException(step: $step, errorCode: $errorCode, message: $message, status: 500);
    }

    private function elapsed(int $startedAt): int
    {
        return intdiv(hrtime(true) - $startedAt, 1_000_000);
    }
}
