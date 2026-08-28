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
/** @mago-expect lint:too-many-methods Synchronization owns validation, transfer, and cleanup at one external-state boundary. */
final readonly class WorktreeSynchronizer
{
    /** @var list<string> */
    private const array REQUIRED_GUEST_SCRIPTS = [
        'converge-app-dev.sh',
        'converge-app-prod-internal-tls.sh',
        'converge-gateway.sh',
        'converge-sample-app.sh',
        'hydrate-orbit.sh',
        'prepare-node.sh',
        'receive-source.sh',
        'verify-topology.sh',
    ];

    public function __construct(
        private GuestTransport $incus,
        private string $repositoryRoot,
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
            $guestScripts = $this->guestScripts($repository->root());
            $guestScriptHash = $this->guestScriptHash($guestScripts);
            $bundle = $temporaryDirectory.'/source.bundle';
            $archive = $temporaryDirectory.'/overlay.tar';
            $manifest = $temporaryDirectory.'/overlay.paths';
            $deletions = $temporaryDirectory.'/overlay.deletions';
            $markerFile = $temporaryDirectory.'/guest-scripts.sha256';
            if (
                file_put_contents($markerFile, $guestScriptHash."\n", LOCK_EX) === false
                || ! chmod($markerFile, 0600)
            ) {
                throw new RuntimeException('Could not create the guest script marker.');
            }
            $paths = $overlay?->paths ?? [];
            $repository->createOverlayArchive($archive, $paths);
            if (file_put_contents($manifest, $paths === [] ? '' : implode("\0", $paths)."\0", LOCK_EX) === false) {
                throw new RuntimeException('Could not create the source manifest.');
            }
            $deletedPaths = array_values(array_filter(
                $paths,
                fn (string $path): bool => ! file_exists($repository->root().'/'.$path),
            ));
            if (
                file_put_contents($deletions, $deletedPaths === [] ? '' : implode("\0", $deletedPaths)."\0", LOCK_EX)
                === false
            ) {
                throw new RuntimeException('Could not create the source deletion manifest.');
            }
            if (! chmod($archive, 0600) || ! chmod($manifest, 0600) || ! chmod($deletions, 0600)) {
                throw new RuntimeException('Could not secure the source transfer files.');
            }

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
            foreach (['gateway', 'app-dev', 'app-prod'] as $role) {
                $this->installGuestScripts($target->instance($role), $guestScripts, $markerFile, $operationId);
            }
            foreach ($roles as $role) {
                $repository->createBundle($bundle, $hostSha, $role['prerequisite']);
                $guestShas[] = $this->transfer(
                    $role['instance'],
                    $hostSha,
                    $overlay?->treeHash ?? $repository->effectiveTreeHash(),
                    $bundle,
                    $archive,
                    $manifest,
                    $deletions,
                    $operationId,
                );
            }

            if (array_unique($guestShas) !== [$hostSha]) {
                throw new RuntimeException('Guest source SHAs do not match the host SHA.');
            }

            $state = new SourceState(
                $hostSha,
                $hostSha,
                $overlay !== null,
                $overlay?->treeHash,
                $paths,
                $operationId,
            );
        } catch (\Throwable $primary) {
            try {
                $this->cleanupTemporaryDirectory($temporaryDirectory);
            } catch (\Throwable $cleanupFailure) {
                throw new RuntimeException(
                    'Source synchronization failed; temporary-directory cleanup also failed: '
                        .$cleanupFailure->getMessage(),
                    0,
                    $primary,
                );
            }

            throw $primary;
        }

        $this->cleanupTemporaryDirectory($temporaryDirectory);

        return $state;
    }

    private function cleanupTemporaryDirectory(string $directory): void
    {
        $entries = scandir($directory);
        if ($entries === false) {
            throw new RuntimeException('Could not inspect the temporary source directory.');
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;
            if (is_file($path) && ! is_link($path) && ! unlink($path)) {
                throw new RuntimeException('Could not remove a temporary source file.');
            }
        }

        if (! rmdir($directory)) {
            throw new RuntimeException('Could not remove the temporary source directory.');
        }
    }

    private function validateWorktree(GitRepository $repository, TopologyTarget $target): void
    {
        $expected = realpath($this->repositoryRoot);
        $worktreeRoot = $repository->root();
        if ($expected === false) {
            throw new InvalidArgumentException('The expected repository does not exist.');
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

        if (! $repository->isLinkedWorktree()) {
            throw new InvalidArgumentException('Feature source must use a registered linked worktree.');
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
        string $deletions,
        string $operationId,
    ): string {
        $prefix = "/var/lib/orbit-e2e/source/{$operationId}";
        $bundleName = $this->fileHash($bundle).'.bundle';
        $archiveName = $this->fileHash($archive).'.tar';
        $manifestName = $this->fileHash($manifest).'.paths';
        $deletionsName = $this->fileHash($deletions).'.deletions';
        $staged = false;
        try {
            $prepare = $this->incus->exec($instance, new GuestCommand([
                'install',
                '-d',
                '-o',
                'orbit',
                '-g',
                'orbit',
                '-m',
                '0700',
                $prefix,
            ]));
            if (! $prepare->successful()) {
                throw new RuntimeException('Guest source staging failed.');
            }
            $staged = true;
            foreach ([
                $bundle => "{$prefix}/{$bundleName}",
                $archive => "{$prefix}/{$archiveName}",
                $manifest => "{$prefix}/{$manifestName}",
                $deletions => "{$prefix}/{$deletionsName}",
            ] as $source => $destination) {
                $this->incus->pushFile($instance, $source, $destination);
            }

            $ownership = $this->incus->exec($instance, new GuestCommand([
                'chown',
                'orbit:orbit',
                "{$prefix}/{$bundleName}",
                "{$prefix}/{$archiveName}",
                "{$prefix}/{$manifestName}",
                "{$prefix}/{$deletionsName}",
            ]));
            if (! $ownership->successful()) {
                throw new RuntimeException('Guest source staging ownership failed.');
            }

            $receive = $this->incus->exec($instance, new GuestCommand([
                'runuser',
                '-u',
                'orbit',
                '--',
                'env',
                'HOME=/home/orbit',
                '/usr/local/bin/receive-source.sh',
                '/home/orbit/orbit',
                $sha,
                "{$prefix}/{$bundleName}",
                "{$prefix}/{$archiveName}",
                "{$prefix}/{$manifestName}",
                "{$prefix}/{$deletionsName}",
                $treeHash,
            ], 300));
            if ($receive->exitCode !== 0) {
                throw new RuntimeException('Guest source installation failed.');
            }
            $hydrate = $this->incus->exec(
                $instance,
                new GuestCommand(
                    [
                        'runuser',
                        '-u',
                        'orbit',
                        '--',
                        'env',
                        'HOME=/home/orbit',
                        'ORBIT_HOME=/home/orbit/.orbit',
                        'ORBIT_GATEWAY_CHECKOUT=/home/orbit/orbit/apps/gateway',
                        'DB_DATABASE=/home/orbit/.orbit/gateway.sqlite',
                        '/usr/local/bin/hydrate-orbit.sh',
                        '/home/orbit/orbit',
                        $sha,
                    ],
                    900,
                ),
            );
            if ($hydrate->exitCode !== 0) {
                throw new RuntimeException('Guest source hydration failed.');
            }

            /** @mago-expect analysis:mixed-assignment JSON evidence is checked against the exact scalar values below. */
            $evidence = json_decode(trim($receive->stdout), true, 16, JSON_THROW_ON_ERROR);
            /** @var mixed $evidence */
            if (
                ! is_array($evidence)
                || ($evidence['sha'] ?? null) !== $sha
                || ($evidence['tree_hash'] ?? null) !== $treeHash
            ) {
                throw new RuntimeException('Guest source evidence does not match the host.');
            }
            $staged = false;
            $this->cleanupStaging($instance, $prefix, 'Guest source staging cleanup failed.');

            return $sha;
        } catch (JsonException $exception) {
            $primary = new RuntimeException('Guest source evidence is invalid.', 0, $exception);
            if ($staged) {
                $this->cleanupAfterFailure($instance, $prefix, 'Guest source staging cleanup failed.', $primary);
            }
            throw $primary;
        } catch (RuntimeException $primary) {
            if ($staged) {
                $this->cleanupAfterFailure($instance, $prefix, 'Guest source staging cleanup failed.', $primary);
            }
            throw $primary;
        }
    }

    /** @return list<string> */
    private function guestScripts(string $worktreeRoot): array
    {
        $directory = $worktreeRoot.'/apps/e2e/resources/guest';
        $paths = [];
        foreach (self::REQUIRED_GUEST_SCRIPTS as $name) {
            $path = $directory.'/'.$name;
            if (
                is_link($path)
                || ! is_file($path)
                || ! is_executable($path)
                || preg_match('/\A[a-z0-9][a-z0-9.-]*\.sh\z/D', $name) !== 1
            ) {
                throw new RuntimeException('Guest script inventory is invalid.');
            }

            $paths[] = $path;
        }
        sort($paths, SORT_STRING);

        return $paths;
    }

    /** @param list<string> $scripts */
    private function guestScriptHash(array $scripts): string
    {
        $context = hash_init('sha256');
        foreach ($scripts as $script) {
            $permissions = fileperms($script);
            if ($permissions === false) {
                throw new RuntimeException('Could not read guest script permissions.');
            }
            hash_update($context, basename($script)."\0".sprintf('%o', $permissions & 07777)."\0");
            $contents = file_get_contents($script);
            if ($contents === false) {
                throw new RuntimeException('Could not read guest script inventory.');
            }
            hash_update($context, $contents."\0");
        }

        return hash_final($context);
    }

    /** @param list<string> $scripts */
    private function installGuestScripts(
        string $instance,
        array $scripts,
        string $markerFile,
        string $operationId,
    ): void {
        $markerContents = file_get_contents($markerFile);
        if ($markerContents === false) {
            throw new RuntimeException('Could not read the guest script marker.');
        }
        $hash = trim($markerContents);
        $marker = '/var/lib/orbit-e2e/guest-scripts.sha256';
        $probe = $this->incus->exec($instance, new GuestCommand(['cat', $marker]));
        $installedScripts = array_map(
            static fn (string $script): string => '/usr/local/bin/'.basename($script),
            $scripts,
        );
        $contentProbe = $this->incus->exec(
            $instance,
            new GuestCommand([
                'sha256sum',
                '--',
                ...$installedScripts,
            ]),
        );
        if (
            $probe->successful()
            && trim($probe->stdout) === $hash
            && $contentProbe->successful()
            && trim($contentProbe->stdout) === $this->guestScriptContentHashes($installedScripts, $scripts)
        ) {
            return;
        }
        $prefix = "/var/lib/orbit-e2e/scripts/{$operationId}";
        $staged = false;
        try {
            $prepare = $this->incus->exec($instance, new GuestCommand(['install', '-d', '-m', '0700', $prefix]));
            if (! $prepare->successful()) {
                throw new RuntimeException("Guest script staging failed on {$instance}.");
            }
            $staged = true;
            foreach ($scripts as $script) {
                $name = basename($script);
                $this->incus->pushFile($instance, $script, "{$prefix}/{$name}");
            }
            foreach ($scripts as $script) {
                $name = basename($script);
                $result = $this->incus->exec($instance, new GuestCommand([
                    'install',
                    '-o',
                    'root',
                    '-g',
                    'root',
                    '-m',
                    '0755',
                    "{$prefix}/{$name}",
                    "/usr/local/bin/{$name}",
                ]));
                if (! $result->successful()) {
                    throw new RuntimeException("Guest script installation failed on {$instance}.");
                }
            }
            $this->incus->pushFile($instance, $markerFile, "{$prefix}/guest-scripts.sha256");
            $markerInstall = $this->incus->exec($instance, new GuestCommand([
                'install',
                '-o',
                'root',
                '-g',
                'root',
                '-m',
                '0644',
                "{$prefix}/guest-scripts.sha256",
                $marker,
            ]));
            if (! $markerInstall->successful()) {
                throw new RuntimeException("Guest script marker installation failed on {$instance}.");
            }
            $staged = false;
            $this->cleanupStaging($instance, $prefix, "Guest script staging cleanup failed on {$instance}.");
        } catch (RuntimeException $primary) {
            if ($staged) {
                $this->cleanupAfterFailure(
                    $instance,
                    $prefix,
                    "Guest script staging cleanup failed on {$instance}.",
                    $primary,
                );
            }
            throw $primary;
        }
    }

    /**
     * @param list<string> $installedScripts
     * @param list<string> $scripts
     */
    private function guestScriptContentHashes(array $installedScripts, array $scripts): string
    {
        $lines = [];
        foreach ($scripts as $index => $script) {
            $contentHash = hash_file('sha256', $script);
            if ($contentHash === false) {
                throw new RuntimeException('Could not hash guest script inventory.');
            }
            $lines[] = $contentHash.'  '.$installedScripts[$index];
        }

        return implode("\n", $lines);
    }

    private function cleanupAfterFailure(
        string $instance,
        string $prefix,
        string $message,
        RuntimeException $primary,
    ): void {
        try {
            $this->cleanupStaging($instance, $prefix, $message);
        } catch (RuntimeException $cleanupFailure) {
            throw new RuntimeException(
                $message.' Primary operation failed: '.$primary->getMessage().'; cleanup also failed: '
                    .$cleanupFailure->getMessage(),
                0,
                $primary,
            );
        }
    }

    private function cleanupStaging(string $instance, string $prefix, string $message): void
    {
        $cleanup = $this->incus->exec($instance, new GuestCommand(['rm', '-r', '--', $prefix]));
        if (! $cleanup->successful()) {
            throw new RuntimeException($message);
        }
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
