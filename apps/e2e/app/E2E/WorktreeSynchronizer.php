<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Git\GitRepository;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\SourceState;
use App\E2E\Value\SyncMode;
use App\E2E\Value\TopologyTarget;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/** @mago-expect lint:cyclomatic-complexity,kan-defect Exact validation and transfer remain one fail-closed operation. */
final readonly class WorktreeSynchronizer
{
    public function __construct(
        private IncusHost $incus,
        private string $repositoryRoot,
        private string $guestScriptDirectory,
    ) {}

    public function sync(TopologyTarget $target, string $worktree, SyncMode $mode): SourceState
    {
        $repository = new GitRepository($worktree);
        $this->validateWorktree($repository, $target);
        $hostSha = $repository->commit();
        $overlay = $repository->dirtyOverlay();
        $operationId = bin2hex(random_bytes(16));
        $temporaryDirectory = $this->temporaryDirectory();

        try {
            $bundle = $temporaryDirectory.'/source.bundle';
            $archive = $temporaryDirectory.'/overlay.tar';
            $manifest = $temporaryDirectory.'/overlay.paths';
            $paths = $overlay?->paths ?? [];
            $repository->createOverlayArchive($archive, $paths);
            file_put_contents($manifest, $paths === [] ? '' : implode("\0", $paths)."\0", LOCK_EX);
            chmod($archive, 0600);
            chmod($manifest, 0600);

            $roles = [];
            foreach (['gateway', 'app-dev'] as $role) {
                $instance = $target->instance($role);
                $guestSha = $this->guestSha($instance);
                $roles[] = [
                    'instance' => $instance,
                    'prerequisite' => $mode === SyncMode::Incremental
                    && $guestSha !== null
                    && $repository->isPrerequisite($guestSha, $hostSha)
                        ? $guestSha
                        : null,
                ];
            }

            $guestShas = [];
            foreach ($roles as $role) {
                $repository->createBundle($bundle, $hostSha, $role['prerequisite']);
                $guestShas[] = $this->transfer(
                    $role['instance'],
                    $hostSha,
                    $overlay?->treeHash ?? $repository->effectiveTreeHash(),
                    $bundle,
                    $archive,
                    $manifest,
                    $operationId,
                );
            }

            if (array_unique($guestShas) !== [$hostSha]) {
                throw new RuntimeException('Guest source SHAs do not match the host SHA.');
            }

            return new SourceState(
                $hostSha,
                $hostSha,
                $overlay !== null,
                $overlay?->treeHash,
                $paths,
                $operationId,
            );
        } finally {
            foreach (glob($temporaryDirectory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($temporaryDirectory);
        }
    }

    private function validateWorktree(GitRepository $repository, TopologyTarget $target): void
    {
        $expected = realpath($this->repositoryRoot);
        $worktreeRoot = $repository->root();
        if ($expected === false || ! str_starts_with($worktreeRoot.'/', $expected.'/')) {
            throw new InvalidArgumentException('The worktree is outside the expected repository.');
        }

        $common = trim($this->git($worktreeRoot, ['rev-parse', '--git-common-dir']));
        $expectedCommon = trim($this->git($expected, ['rev-parse', '--git-common-dir']));
        $commonReal = realpath(str_starts_with($common, '/') ? $common : $worktreeRoot.'/'.$common);
        $expectedReal = realpath(
            str_starts_with($expectedCommon, '/') ? $expectedCommon : $expected.'/'.$expectedCommon,
        );
        if ($commonReal === false || $expectedReal === false || $commonReal !== $expectedReal) {
            throw new InvalidArgumentException('The worktree repository identity does not match.');
        }

        if ($target->isStandby()) {
            if ($repository->dirtyOverlay() !== null) {
                throw new InvalidArgumentException('The standby source worktree must be clean.');
            }

            return;
        }

        if (! str_contains(strtolower($repository->branch()), strtolower($target->issue))) {
            throw new InvalidArgumentException('The worktree branch does not match the issue.');
        }
    }

    private function guestSha(string $instance): ?string
    {
        $result = $this->incus->exec($instance, new GuestCommand([
            'git',
            '-C',
            '/home/orbit/orbit',
            'rev-parse',
            '--verify',
            'HEAD^{commit}',
        ]));
        $sha = strtolower(trim($result->stdout));

        return $result->exitCode === 0 && preg_match('/\A[0-9a-f]{40}\z/D', $sha) === 1 ? $sha : null;
    }

    /** @mago-expect lint:excessive-parameter-list Transfer inputs remain explicit at the Incus trust boundary. */
    private function transfer(
        string $instance,
        string $sha,
        string $treeHash,
        string $bundle,
        string $archive,
        string $manifest,
        string $operationId,
    ): string {
        $prefix = "/var/lib/orbit-e2e/source/{$operationId}";
        $bundleName = $this->fileHash($bundle).'.bundle';
        $archiveName = $this->fileHash($archive).'.tar';
        $manifestName = $this->fileHash($manifest).'.paths';
        $prepare = $this->incus->exec($instance, new GuestCommand(['install', '-d', '-m', '0700', $prefix]));
        if (! $prepare->successful()) {
            throw new RuntimeException('Guest source staging failed.');
        }
        foreach ([
            $this->guestScriptDirectory.'/receive-source.sh' => "{$prefix}/receive-source.sh",
            $this->guestScriptDirectory.'/hydrate-orbit.sh' => "{$prefix}/hydrate-orbit.sh",
            $bundle => "{$prefix}/{$bundleName}",
            $archive => "{$prefix}/{$archiveName}",
            $manifest => "{$prefix}/{$manifestName}",
        ] as $source => $destination) {
            $this->incus->pushFile($instance, $source, $destination);
        }

        $receive = $this->incus->exec($instance, new GuestCommand([
            'bash',
            "{$prefix}/receive-source.sh",
            '/home/orbit/orbit',
            $sha,
            "{$prefix}/{$bundleName}",
            "{$prefix}/{$archiveName}",
            "{$prefix}/{$manifestName}",
            $treeHash,
        ], 300));
        if ($receive->exitCode !== 0) {
            throw new RuntimeException('Guest source installation failed.');
        }
        $hydrate = $this->incus->exec($instance, new GuestCommand([
            'bash',
            "{$prefix}/hydrate-orbit.sh",
            '/home/orbit/orbit',
            $sha,
        ], 900));
        if ($hydrate->exitCode !== 0) {
            throw new RuntimeException('Guest source hydration failed.');
        }

        try {
            /** @mago-expect analysis:mixed-assignment JSON evidence is checked against the exact scalar values below. */
            $evidence = json_decode(trim($receive->stdout), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Guest source evidence is invalid.', 0, $exception);
        }
        /** @var mixed $evidence */
        if (
            ! is_array($evidence)
            || ($evidence['sha'] ?? null) !== $sha
            || ($evidence['tree_hash'] ?? null) !== $treeHash
        ) {
            throw new RuntimeException('Guest source evidence does not match the host.');
        }

        return $sha;
    }

    /** @param list<string> $arguments */
    private function git(string $path, array $arguments): string
    {
        $result = \Illuminate\Support\Facades\Process::path($path)->run(['git', ...$arguments]);
        if ($result->failed()) {
            throw new InvalidArgumentException('Git repository validation failed.');
        }

        return $result->output();
    }

    private function temporaryDirectory(): string
    {
        $path = sys_get_temp_dir().'/orbit-source-'.bin2hex(random_bytes(12));
        if (! mkdir($path, 0700)) {
            throw new RuntimeException('Could not create the source transfer directory.');
        }

        return $path;
    }

    private function fileHash(string $path): string
    {
        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            throw new RuntimeException('Could not hash a source transfer file.');
        }

        return $hash;
    }
}
