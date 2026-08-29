<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Git\GitRepository;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\GuestCommandResult;
use App\E2E\Value\OperationId;
use App\E2E\Value\SourceState;
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
        private OperationId $operation,
    ) {}

    public function sync(TopologyTarget $target, string $worktree): SourceState
    {
        $repository = new GitRepository($worktree);
        $this->validateWorktree($repository, $target);
        $hostSha = $repository->commit();
        $overlay = $repository->dirtyOverlay();
        $operationId = $this->operation->value;
        $temporaryDirectory = $this->temporaryDirectory();

        try {
            $guestScripts = $this->guestScripts($repository->root());
            $guestScriptHash = $this->guestScriptHash($guestScripts);
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

            $effectiveTreeHash = $overlay?->treeHash ?? $repository->effectiveTreeHash();
            [$guestShas, $scriptStatus] = $this->guestPreflight($target, $guestScripts, $guestScriptHash);
            $roles = [];
            foreach (['gateway', 'app-dev'] as $role) {
                $instance = $target->instance($role);
                $guestSha = is_array($guestShas[$role]) ? $guestShas[$role]['sha'] : null;
                $bundleRequired = $guestSha !== $hostSha;
                $roles[$role] = [
                    'instance' => $instance,
                    'bundleRequired' => $bundleRequired,
                    'prerequisite' => $bundleRequired
                        ? $this->bundlePrerequisite($repository, $guestSha, $hostSha)
                        : null,
                    'bundle' => null,
                ];
            }

            $changedScriptRoles = [];
            foreach (['gateway', 'app-dev', 'app-prod'] as $role) {
                if (! $scriptStatus[$role]) {
                    $changedScriptRoles[$role] = $target->instance($role);
                }
            }
            $this->installGuestScripts($changedScriptRoles, $guestScripts, $markerFile, $operationId);
            $roles = array_filter(
                $roles,
                fn (array $_transfer, string $role): bool => ! $this->sourceStateMatchesRole(
                    $guestShas[$role] ?? null,
                    $hostSha,
                    $effectiveTreeHash,
                    $overlay === null,
                ),
                ARRAY_FILTER_USE_BOTH,
            );
            if ($roles === []) {
                $state = new SourceState(
                    $hostSha,
                    $hostSha,
                    $overlay !== null,
                    $overlay?->treeHash,
                    $paths,
                    $operationId,
                );
                $this->cleanupTemporaryDirectory($temporaryDirectory);

                return $state;
            }
            $bundles = [];
            foreach ($roles as $role => $transfer) {
                if (! $transfer['bundleRequired']) {
                    continue;
                }
                $bundleKey = $transfer['prerequisite'] ?? 'full';
                if (! isset($bundles[$bundleKey])) {
                    $bundles[$bundleKey] = $temporaryDirectory.'/source-'.$bundleKey.'.bundle';
                    $repository->createBundle($bundles[$bundleKey], $hostSha, $transfer['prerequisite']);
                }
                $roles[$role]['bundle'] = $bundles[$bundleKey];
            }
            $guestShas = $this->transfer(
                $roles,
                $hostSha,
                $effectiveTreeHash,
                $archive,
                $manifest,
                $deletions,
                $operationId,
            );

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

    private function bundlePrerequisite(GitRepository $repository, ?string $guestSha, string $hostSha): ?string
    {
        if ($guestSha === null || $guestSha === $hostSha) {
            return null;
        }

        try {
            return $repository->hasCommit($guestSha) ? $guestSha : null;
        } catch (InvalidArgumentException) {
            return null;
        }
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

        if (! $target->matchesBranch($repository->branch())) {
            throw new InvalidArgumentException('The worktree branch does not match the issue.');
        }
    }

    /** @return array{gateway:array{sha:string,markerSha:?string,tree:?string,clean:bool,hydrated:bool}|null,app-dev:array{sha:string,markerSha:?string,tree:?string,clean:bool,hydrated:bool}|null} */
    /**
     * @param list<string> $scripts
     * @return array{0: array<string, array{sha: string, markerSha: ?string, tree: ?string, clean: bool, hydrated: bool}|null>, 1: array{gateway: bool, 'app-dev': bool, 'app-prod': bool}}
     */
    private function guestPreflight(TopologyTarget $target, array $scripts, string $scriptHash): array
    {
        $commands = [];
        foreach (['gateway', 'app-dev'] as $role) {
            $commands["guest-sha.{$role}"] = [
                'instance' => $target->instance($role),
                'command' => new GuestCommand([
                    'git',
                    '-C',
                    '/home/orbit/orbit',
                    'rev-parse',
                    '--verify',
                    'HEAD^{commit}',
                ]),
            ];
            $commands["guest-marker.{$role}"] = [
                'instance' => $target->instance($role),
                'command' => new GuestCommand(['cat', '/home/orbit/orbit/.git/orbit-source-state']),
            ];
            $commands["guest-status.{$role}"] = [
                'instance' => $target->instance($role),
                'command' => new GuestCommand([
                    'git',
                    '-C',
                    '/home/orbit/orbit',
                    'status',
                    '--porcelain=v1',
                    '--untracked-files=all',
                ]),
            ];
            $commands["guest-hydration.{$role}"] = [
                'instance' => $target->instance($role),
                'command' => new GuestCommand(['cat', '/home/orbit/orbit/.git/orbit-hydrated.sha']),
            ];
        }
        $installedScripts = array_map(
            static fn (string $script): string => '/usr/local/bin/'.basename($script),
            $scripts,
        );
        $expectedContentHashes = $this->guestScriptContentHashes($installedScripts, $scripts);
        foreach (['gateway', 'app-dev', 'app-prod'] as $role) {
            $commands["script-marker.{$role}"] = [
                'instance' => $target->instance($role),
                'command' => new GuestCommand(['cat', '/var/lib/orbit-e2e/guest-scripts.sha256']),
            ];
            $commands["script-content.{$role}"] = [
                'instance' => $target->instance($role),
                'command' => new GuestCommand(['sha256sum', '--', ...$installedScripts]),
            ];
        }
        $results = $this->incus->execAll($commands);
        $guestShas = [];
        foreach (['gateway', 'app-dev'] as $role) {
            $result = $results["guest-sha.{$role}"] ?? null;
            if (! $result instanceof GuestCommandResult) {
                throw new RuntimeException('Guest SHA batch result is invalid.');
            }
            $sha = strtolower(trim($result->stdout));
            $marker = $results["guest-marker.{$role}"] ?? null;
            $status = $results["guest-status.{$role}"] ?? null;
            $hydration = $results["guest-hydration.{$role}"] ?? null;
            $tree = null;
            if ($marker instanceof GuestCommandResult && $marker->exitCode === 0) {
                try {
                    $markerState = json_decode(trim($marker->stdout), true, 512, JSON_THROW_ON_ERROR);
                    $markerSha = $markerState['sha'] ?? null;
                    $tree = $markerState['tree'] ?? null;
                    if (
                        ! is_string($markerSha)
                        || preg_match('/\A[0-9a-f]{40}\z/D', strtolower($markerSha)) !== 1
                    ) {
                        $markerSha = null;
                    }
                } catch (JsonException) {
                    $markerSha = null;
                }
            } else {
                $markerSha = null;
            }
            $guestShas[$role] =
                $result->exitCode === 0 && preg_match('/\A[0-9a-f]{40}\z/D', $sha) === 1
                    ? [
                        'sha' => $sha,
                        'markerSha' => $markerSha,
                        'tree' => is_string($tree) && preg_match('/\A[0-9a-f]{64}\z/D', $tree) === 1
                            ? strtolower($tree)
                            : null,
                        'clean' =>
                            $status instanceof GuestCommandResult
                                && $status->successful()
                                && trim($status->stdout) === '',
                        'hydrated' =>
                            $hydration instanceof GuestCommandResult
                                && $hydration->successful()
                                && trim($hydration->stdout) === $sha,
                    ]
                    : null;
        }

        $scriptStatus = [];
        foreach (['gateway', 'app-dev', 'app-prod'] as $role) {
            $markerProbe = $results["script-marker.{$role}"] ?? null;
            $contentProbe = $results["script-content.{$role}"] ?? null;
            if (! $markerProbe instanceof GuestCommandResult || ! $contentProbe instanceof GuestCommandResult) {
                throw new RuntimeException('Guest script probe batch result is invalid.');
            }
            $scriptStatus[$role] =
                $markerProbe->successful()
                && trim($markerProbe->stdout) === $scriptHash
                && $contentProbe->successful()
                && trim($contentProbe->stdout) === $expectedContentHashes;
        }

        return [$guestShas, $scriptStatus];
    }

    private function sourceStateMatchesRole(mixed $state, string $sha, string $tree, bool $mustBeClean): bool
    {
        return (
            is_array($state)
            && ($state['sha'] ?? null) === $sha
            && ($state['markerSha'] ?? null) === $sha
            && ($state['tree'] ?? null) === $tree
            && ($state['hydrated'] ?? false) === true
            && (! $mustBeClean || ($state['clean'] ?? false) === true)
        );
    }

    /**
     * @param array<string, array{
     *     instance:string,
     *     bundleRequired:bool,
     *     prerequisite:?string,
     *     bundle:?string
     * }> $roles
     * @return list<string>
     * @mago-expect lint:excessive-parameter-list Transfer inputs remain explicit at the Incus trust boundary.
     */
    private function transfer(
        array $roles,
        string $sha,
        string $treeHash,
        string $archive,
        string $manifest,
        string $deletions,
        string $operationId,
    ): array {
        $prefix = "/var/lib/orbit-e2e/source/{$operationId}";
        $archiveName = $this->fileHash($archive).'.tar';
        $manifestName = $this->fileHash($manifest).'.paths';
        $deletionsName = $this->fileHash($deletions).'.deletions';
        $transfers = [];
        foreach ($roles as $role => $transfer) {
            $files = [];
            if ($transfer['bundle'] !== null) {
                $bundleName = $this->fileHash($transfer['bundle']).'.bundle';
                $files['bundle'] = [$transfer['bundle'], "{$prefix}/{$bundleName}"];
            }
            $files += [
                'archive' => [$archive, "{$prefix}/{$archiveName}"],
                'manifest' => [$manifest, "{$prefix}/{$manifestName}"],
                'deletions' => [$deletions, "{$prefix}/{$deletionsName}"],
            ];
            $transfers[$role] = [
                'instance' => $transfer['instance'],
                'files' => $files,
            ];
        }
        $staged = array_map(static fn (array $transfer): string => $transfer['instance'], $transfers);
        try {
            $prepare = [];
            foreach ($transfers as $role => $transfer) {
                $prepare["source-prepare.{$role}"] = [
                    'instance' => $transfer['instance'],
                    'command' => new GuestCommand([
                        'install',
                        '-d',
                        '-o',
                        'orbit',
                        '-g',
                        'orbit',
                        '-m',
                        '0700',
                        $prefix,
                    ]),
                ];
            }
            $this->assertBatchSuccessful($this->incus->execAll($prepare), 'Guest source staging failed.');

            $pushes = [];
            foreach ($transfers as $role => $transfer) {
                foreach ($transfer['files'] as $name => [$source, $destination]) {
                    $pushes["source-push.{$role}.{$name}"] = compact('source', 'destination')
                    + [
                        'instance' => $transfer['instance'],
                    ];
                }
            }
            $this->incus->pushFiles($pushes);

            $ownership = [];
            foreach ($transfers as $role => $transfer) {
                $ownership["source-ownership.{$role}"] = [
                    'instance' => $transfer['instance'],
                    'command' => new GuestCommand([
                        'chown',
                        'orbit:orbit',
                        ...array_column($transfer['files'], 1),
                    ]),
                ];
            }
            $this->assertBatchSuccessful(
                $this->incus->execAll($ownership),
                'Guest source staging ownership failed.',
            );

            $receive = [];
            foreach ($transfers as $role => $transfer) {
                $bundle = $transfer['files']['bundle'][1] ?? '-';
                $receive["source-receive.{$role}"] = [
                    'instance' => $transfer['instance'],
                    'command' => new GuestCommand([
                        'runuser',
                        '-u',
                        'orbit',
                        '--',
                        'env',
                        'HOME=/home/orbit',
                        '/usr/local/bin/receive-source.sh',
                        '/home/orbit/orbit',
                        $sha,
                        $bundle,
                        $transfer['files']['archive'][1],
                        $transfer['files']['manifest'][1],
                        $transfer['files']['deletions'][1],
                        $treeHash,
                    ], 300),
                ];
            }
            $receiveResults = $this->incus->execAll($receive);
            $this->assertBatchSuccessful($receiveResults, 'Guest source installation failed.');
            $this->validateSourceEvidence($receiveResults, $transfers, $sha, $treeHash);

            $hydrate = [];
            foreach ($transfers as $role => $transfer) {
                $hydrate["source-hydrate.{$role}"] = [
                    'instance' => $transfer['instance'],
                    'command' => new GuestCommand([
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
                    ], 900),
                ];
            }
            $this->assertBatchSuccessful($this->incus->execAll($hydrate), 'Guest source hydration failed.');

            $cleanupInstances = $staged;
            $staged = [];
            $this->cleanupSourceStaging($cleanupInstances, $prefix, 'Guest source staging cleanup failed.');

            return array_fill(0, count($transfers), $sha);
        } catch (JsonException $exception) {
            $primary = new RuntimeException('Guest source evidence is invalid.', 0, $exception);
            if ($staged !== []) {
                $this->cleanupSourceAfterFailure($staged, $prefix, 'Guest source staging cleanup failed.', $primary);
            }
            throw $primary;
        } catch (RuntimeException $primary) {
            if ($staged !== []) {
                $this->cleanupSourceAfterFailure($staged, $prefix, 'Guest source staging cleanup failed.', $primary);
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

    /**
     * @param list<string> $scripts
     * @return array{gateway:bool,app-dev:bool,app-prod:bool}
     */
    private function unchangedGuestScripts(TopologyTarget $target, array $scripts, string $hash): array
    {
        $installedScripts = array_map(
            static fn (string $script): string => '/usr/local/bin/'.basename($script),
            $scripts,
        );
        $expectedContentHashes = $this->guestScriptContentHashes($installedScripts, $scripts);
        $marker = '/var/lib/orbit-e2e/guest-scripts.sha256';
        $commands = [];
        foreach (['gateway', 'app-dev', 'app-prod'] as $role) {
            $instance = $target->instance($role);
            $commands["script-marker.{$role}"] = [
                'instance' => $instance,
                'command' => new GuestCommand(['cat', $marker]),
            ];
            $commands["script-content.{$role}"] = [
                'instance' => $instance,
                'command' => new GuestCommand(['sha256sum', '--', ...$installedScripts]),
            ];
        }
        $results = $this->incus->execAll($commands);
        $status = [];
        foreach (['gateway', 'app-dev', 'app-prod'] as $role) {
            $markerProbe = $results["script-marker.{$role}"] ?? null;
            $contentProbe = $results["script-content.{$role}"] ?? null;
            if (! $markerProbe instanceof GuestCommandResult || ! $contentProbe instanceof GuestCommandResult) {
                throw new RuntimeException('Guest script probe batch result is invalid.');
            }
            $status[$role] =
                $markerProbe->successful()
                && trim($markerProbe->stdout) === $hash
                && $contentProbe->successful()
                && trim($contentProbe->stdout) === $expectedContentHashes;
        }

        return $status;
    }

    /**
     * @param array<string, string> $instances
     * @param list<string> $scripts
     */
    private function installGuestScripts(
        array $instances,
        array $scripts,
        string $markerFile,
        string $operationId,
    ): void {
        if ($instances === []) {
            return;
        }

        $marker = '/var/lib/orbit-e2e/guest-scripts.sha256';
        $prefix = "/var/lib/orbit-e2e/scripts/{$operationId}";
        $staged = $instances;
        try {
            $prepare = [];
            foreach ($instances as $role => $instance) {
                $prepare["script-prepare.{$role}"] = [
                    'instance' => $instance,
                    'command' => new GuestCommand(['install', '-d', '-m', '0700', $prefix]),
                ];
            }
            $this->assertBatchSuccessful($this->incus->execAll($prepare), 'Guest script staging failed.');

            $pushes = [];
            foreach ($instances as $role => $instance) {
                foreach ($scripts as $script) {
                    $name = basename($script);
                    $pushes["script-push.{$role}.{$name}"] = [
                        'instance' => $instance,
                        'source' => $script,
                        'destination' => "{$prefix}/{$name}",
                    ];
                }
                $pushes["script-push.{$role}.marker"] = [
                    'instance' => $instance,
                    'source' => $markerFile,
                    'destination' => "{$prefix}/guest-scripts.sha256",
                ];
            }
            $this->incus->pushFiles($pushes);

            $installs = [];
            foreach ($instances as $role => $instance) {
                foreach ($scripts as $script) {
                    $name = basename($script);
                    $installs["script-install.{$role}.{$name}"] = [
                        'instance' => $instance,
                        'command' => new GuestCommand([
                            'install',
                            '-o',
                            'root',
                            '-g',
                            'root',
                            '-m',
                            '0755',
                            "{$prefix}/{$name}",
                            "/usr/local/bin/{$name}",
                        ]),
                    ];
                }
            }
            $this->assertBatchSuccessful($this->incus->execAll($installs), 'Guest script installation failed.');

            $markers = [];
            foreach ($instances as $role => $instance) {
                $markers["script-marker-install.{$role}"] = [
                    'instance' => $instance,
                    'command' => new GuestCommand([
                        'install',
                        '-o',
                        'root',
                        '-g',
                        'root',
                        '-m',
                        '0644',
                        "{$prefix}/guest-scripts.sha256",
                        $marker,
                    ]),
                ];
            }
            $this->assertBatchSuccessful($this->incus->execAll($markers), 'Guest script marker installation failed.');

            $cleanupInstances = $staged;
            $staged = [];
            $this->cleanupStagingBatch(
                $cleanupInstances,
                $prefix,
                'script-cleanup',
                'Guest script staging cleanup failed.',
            );
        } catch (RuntimeException $primary) {
            if ($staged !== []) {
                $this->cleanupStagingAfterFailure(
                    $staged,
                    $prefix,
                    'script-cleanup',
                    'Guest script staging cleanup failed.',
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

    /** @param array<string, GuestCommandResult> $results */
    private function assertBatchSuccessful(array $results, string $message): void
    {
        $failed = [];
        foreach ($results as $label => $result) {
            if (! $result->successful()) {
                $failed[] = $label;
            }
        }
        if ($failed !== []) {
            throw new RuntimeException($message.' Failed operations: '.implode(', ', $failed).'.');
        }
    }

    /**
     * @param array<string, GuestCommandResult> $results
     * @param array<string, array{instance:string, files:array<string, array{string, string}>}> $transfers
     * @throws JsonException
     */
    private function validateSourceEvidence(array $results, array $transfers, string $sha, string $treeHash): void
    {
        foreach ($transfers as $role => $_transfer) {
            $result = $results["source-receive.{$role}"] ?? null;
            if (! $result instanceof GuestCommandResult) {
                throw new RuntimeException('Guest source batch result is invalid.');
            }
            /** @mago-expect analysis:mixed-assignment JSON evidence is checked against exact scalar values. */
            $evidence = json_decode(trim($result->stdout), true, 16, JSON_THROW_ON_ERROR);
            /** @var mixed $evidence */
            if (
                ! is_array($evidence)
                || ($evidence['sha'] ?? null) !== $sha
                || ($evidence['tree_hash'] ?? null) !== $treeHash
            ) {
                throw new RuntimeException('Guest source evidence does not match the host.');
            }
        }
    }

    /** @param array<string, string> $instances */
    private function cleanupSourceAfterFailure(
        array $instances,
        string $prefix,
        string $message,
        RuntimeException $primary,
    ): void {
        $this->cleanupStagingAfterFailure($instances, $prefix, 'source-cleanup', $message, $primary);
    }

    /** @param array<string, string> $instances */
    private function cleanupSourceStaging(array $instances, string $prefix, string $message): void
    {
        $this->cleanupStagingBatch($instances, $prefix, 'source-cleanup', $message);
    }

    /** @param array<string, string> $instances */
    private function cleanupStagingAfterFailure(
        array $instances,
        string $prefix,
        string $labelPrefix,
        string $message,
        RuntimeException $primary,
    ): void {
        try {
            $this->cleanupStagingBatch($instances, $prefix, $labelPrefix, $message);
        } catch (RuntimeException $cleanupFailure) {
            throw new RuntimeException(
                $message.' Primary operation failed: '.$primary->getMessage().'; cleanup also failed: '
                    .$cleanupFailure->getMessage(),
                0,
                $primary,
            );
        }
    }

    /** @param array<string, string> $instances */
    private function cleanupStagingBatch(
        array $instances,
        string $prefix,
        string $labelPrefix,
        string $message,
    ): void {
        $commands = [];
        foreach ($instances as $role => $instance) {
            $commands["{$labelPrefix}.{$role}"] = [
                'instance' => $instance,
                'command' => new GuestCommand(['rm', '-rf', '--', $prefix]),
            ];
        }
        $this->assertBatchSuccessful($this->incus->execAll($commands), $message);
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
