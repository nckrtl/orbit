<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\AppDev\AnnotatorEndpoint;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\InstanceSandboxGuard;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\Transfer\InstanceTransferSource;
use App\Domain\Instances\Transfer\TransferCheckout;
use App\Domain\Instances\Transfer\TransferCleanupResult;
use App\Domain\Instances\Transfer\TransferSourceCapture;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\ApplicationDirectory;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\SourceControl\WorkspaceGit;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\TaskWorkerUser;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final readonly class RemoteInstanceTransferSource implements InstanceTransferSource
{
    public function __construct(
        private DevelopmentSshExecutor $ssh,
        private ProcessRunner $processes,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
    ) {}

    public function capture(Instance $instance, ?string $sqliteSourcePath = null): TransferSourceCapture
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        $instance->loadMissing('node');
        $layout = InstanceSourceLayout::from($instance->source_layout);
        $transfer = InstanceTransfer::query()->where('instance_id', $instance->id)->open()->first();
        foreach ($instance->processes as $process) {
            if ($process->isAnnotator()) {
                $app = $instance->appConfiguration($process->app)['name'];
                if (! isset($transfer?->app_journal[$app]['annotator'])) {
                    throw $this->failed();
                }
            }
        }
        $archives = $transfer === null ? [] : $this->captureAnnotators($instance, $transfer);
        $result = $this->ssh->execute(
            $instance->node,
            new RemoteCommand(
                arguments: ['bash', '-seu', '--', $instance->checkout_path, $layout->value, $sqliteSourcePath ?? '', ...array_merge(...array_map(fn (array $app): array => [$instance->applicationDirectory($app['name']).'/.env', $instance->applicationDirectory($app['name']).'/.env.testing'], $instance->effectiveApps()))],
                input: $this->captureScript(),
            ),
            step: 'app-instance-transfer-capture',
            errorCode: 'instance.transfer_failed',
        );
        $facts = $this->facts($result->stdout);

        return new TransferSourceCapture(
            instanceId: $instance->id,
            nodeId: $instance->node_id,
            layout: $layout,
            sourcePath: $instance->checkout_path,
            commonRepositoryPath: $facts['common'] !== ''
                ? $facts['common']
                : $instance->registration_common_repository_path,
            head: $facts['head'],
            branch: $facts['branch'] === '' ? null : $facts['branch'],
            detached: $facts['detached'] === '1',
            archiveIdentity: $facts['archive'],
            refs: $facts['refs'] === '' ? [] : explode(' ', $facts['refs']),
            transferId: $transfer?->id,
            annotatorArchives: $archives,
        );
    }

    public function materialize(
        TransferSourceCapture $capture,
        Node $destination,
        StoragePath $path,
    ): TransferCheckout {
        $source = Node::query()->findOrFail($capture->nodeId);
        $archive = $this->stageArchive($source, $capture);

        try {
            $this->uploadArchive($destination, $archive, $path, $capture);
            foreach ($capture->annotatorArchives as $app => $entry) {
                $this->restoreAnnotator($source, $destination, $capture, $app, $entry);
            }
        } finally {
            if (is_file($archive)) {
                unlink($archive);
            }
        }

        return new TransferCheckout(
            nodeId: $destination->id,
            path: $path->value,
            layout: InstanceSourceLayout::Checkout,
            head: $capture->head,
            branch: $capture->branch,
            detached: $capture->detached,
        );
    }

    public function discardDestination(Node $node, StoragePath $path): void
    {
        $transfer = InstanceTransfer::query()->where('destination_node_id', $node->id)->where('destination_path', $path->value)->whereNull('cutover_at')->open()->first();
        if ($transfer !== null) {
            $this->removePreparedStores($node, $transfer);
            $this->removeArchives($transfer);
        }
        $this->ssh->execute(
            $node,
            new RemoteCommand(
                arguments: ['bash', '-seu', '--', $path->value],
                input: <<<'BASH'
                    destination=$1
                    if [ -e "$destination" ] || [ -L "$destination" ]; then
                      rm -rf -- "$destination"
                    fi
                    BASH,
            ),
            step: 'app-instance-transfer-discard',
            errorCode: 'instance.transfer_failed',
        );
    }

    public function verifyDestination(InstanceTransfer $transfer): void
    {
        $arguments = ['bash', '-seu', '--'];
        if ($transfer->app_journal === null || $transfer->instance_id === null) {
            throw new RuntimeConvergenceException('app-instance-transfer-verify-annotator', 'instance.transfer_cleanup_conflict', 'The destination restoration journal is missing.');
        }
        foreach ($transfer->app_journal as $app => $entry) {
            $annotator = $entry['annotator'] ?? null;
            if ($annotator === null) {
                continue;
            }
            $store = AnnotatorEndpoint::store($transfer->instance_id, $app);
            if (! isset($annotator['restored_store'], $annotator['attempt']) || $annotator['restored_store'] !== $store) {
                throw new RuntimeConvergenceException('app-instance-transfer-verify-annotator', 'instance.transfer_cleanup_conflict', 'The destination app store receipt is missing or foreign.');
            }
            $arguments[] = $store;
            $arguments[] = "{$transfer->id}:{$annotator['attempt']}:{$transfer->instance_id}:{$app}";
        }
        if (count($arguments) === 3) {
            return;
        }
        $this->ssh->execute(Node::query()->findOrFail($transfer->destination_node_id), new RemoteCommand(
            $arguments,
            'sudo python3 - "$(id -u)" "$@" <<\'PY\''."\n".AnnotatorTransferProgram::verify()."\nPY",
        ), step: 'app-instance-transfer-verify-annotator', errorCode: 'instance.transfer_cleanup_conflict');
    }

    public function cleanupSource(InstanceTransfer $transfer): TransferCleanupResult
    {
        $source = Node::query()->findOrFail($transfer->source_node_id);
        $common = $transfer->common_repository_path;
        if ($transfer->app_journal === null) {
            return new TransferCleanupResult(false, true, ['app-journal']);
        }
        foreach ($transfer->app_journal as $app => $entry) {
            if (isset($entry['annotator'])) {
                $store = $entry['annotator']['source_store'];
                $expected = AnnotatorEndpoint::store($transfer->instance_id ?? throw $this->failed(), $app);
                $legacy = count($transfer->app_journal) === 1 ? AnnotatorEndpoint::store($transfer->instance_id ?? throw $this->failed()) : null;
                if ($store !== $expected && $store !== $legacy) {
                    return new TransferCleanupResult(false, true, ['annotator-store-ownership']);
                }
            }
        }

        // Validate every app first, including when this adapter is called outside the action.
        $this->verifyDestination($transfer);
        try {
            $this->ssh->execute(
                $source,
                new RemoteCommand(
                    arguments: [
                        'bash',
                        '-seu',
                        '--',
                        $transfer->source_path,
                        $transfer->source_layout->value,
                        $common ?? '',
                        ...array_values(array_map(static fn (array $entry): string => $entry['annotator']['source_store'], array_filter($transfer->app_journal ?? [], static fn (array $entry): bool => isset($entry['annotator'])))),
                    ],
                    input: $this->cleanupScript(),
                ),
                step: 'app-instance-transfer-cleanup',
                errorCode: 'instance.transfer_cleanup_incomplete',
            );
        } catch (RuntimeConvergenceException) {
            return new TransferCleanupResult(false, true, ['source-placement']);
        }

        try {
            $this->removePreparedStores(Node::query()->findOrFail($transfer->destination_node_id), $transfer, rollback: false);
            $this->removeArchives($transfer);
        } catch (Throwable) {
            return new TransferCleanupResult(false, true, ['annotator-archives']);
        }

        return new TransferCleanupResult(true, true);
    }

    /** @return array<string, array{source_store: string, archive: string, attempt: string, restored_store?: string, staging_store?: string, ownership_receipt?: string}> */
    private function captureAnnotators(Instance $instance, InstanceTransfer $transfer): array
    {
        $journal = $transfer->app_journal ?? [];
        if ($transfer->cutover_at !== null || $transfer->source_node_id !== $instance->node_id) {
            throw $this->failed();
        }
        foreach ($journal as $entry) {
            if (isset($entry['annotator']['restored_store']) || isset($entry['annotator']['staging_store']) || isset($entry['annotator']['ownership_receipt'])) {
                throw $this->failed(); // Prepared receipts must survive until rollback, never recapture.
            }
        }
        $archives = [];
        foreach ($journal as $app => &$entry) {
            if (! isset($entry['annotator'])) {
                continue;
            }
            $store = $entry['annotator']['source_store'];
            if ($store !== AnnotatorEndpoint::forInstance($instance, $app)) {
                throw $this->failed();
            }
            $attempt = $entry['annotator']['attempt'] ?? (string) Str::uuid();
            $archive = $entry['annotator']['archive'] ?? "/tmp/orbit-transfer-{$transfer->id}-{$attempt}-{$app}.tar";
            $entry['annotator'] = [...$entry['annotator'], 'source_store' => $store, 'archive' => $archive, 'attempt' => $attempt];
            $transfer->update(['app_journal' => $journal]);
            $this->ssh->execute($instance->node, new RemoteCommand(
                ['bash', '-seu', '--', $store, $archive],
                <<<'BASH'
                    store=$1
                    archive=$2
                    test -d "$store" && test ! -L "$store" && test -O "$store"
                    test "$(realpath -e -- "$store")" = "$store"
                    umask 077
                    test ! -L "$archive"
                    if [ -e "$archive" ]; then test -f "$archive" && test -O "$archive"; fi
                    python3 - "$store" <<'PY'
                    import os, stat, sys
                    def fail(error):
                        raise error
                    for directory, children, files in os.walk(sys.argv[1], onerror=fail, followlinks=False):
                        for name in children + files:
                            metadata = os.lstat(os.path.join(directory, name))
                            if metadata.st_uid != os.geteuid() or not (stat.S_ISDIR(metadata.st_mode) or stat.S_ISREG(metadata.st_mode)):
                                raise RuntimeError('foreign annotator content')
                            if stat.S_ISREG(metadata.st_mode) and metadata.st_nlink != 1:
                                raise RuntimeError('linked annotator content')
                    PY
                    tar --owner=0 --group=0 --exclude=./.orbit-transfer-owner -cf "$archive" -C "$store" .
                    BASH,
            ), step: 'app-instance-transfer-capture-annotator', errorCode: 'instance.transfer_failed');
            $archives[$app] = $entry['annotator'];
        }
        unset($entry);

        return $archives;
    }

    /** @param array{source_store: string, archive: string, attempt: string, restored_store?: string, staging_store?: string, ownership_receipt?: string} $entry */
    private function restoreAnnotator(Node $source, Node $destination, TransferSourceCapture $capture, string $app, array $entry): void
    {
        $transferId = $capture->transferId ?? throw $this->failed();
        $transfer = InstanceTransfer::query()->findOrFail($transferId);
        if ($transfer->instance_id !== $capture->instanceId || $transfer->source_node_id !== $source->id || $transfer->destination_node_id !== $destination->id || ! isset($transfer->app_journal[$app]['annotator'])) {
            throw $this->failed();
        }
        $directory = storage_path("app/transfer-staging/{$transferId}/{$entry['attempt']}/annotator");
        new Filesystem()->ensureDirectoryExists($directory, 0700);
        if (realpath($directory) !== $directory) {
            throw $this->failed();
        }
        $local = $directory.'/'.$app.'.tar';
        if (file_exists($local) || is_link($local)) {
            if (! is_file($local) || is_link($local) || fileowner($local) !== posix_geteuid() || ! unlink($local)) {
                throw $this->failed();
            }
        }
        $file = fopen($local, 'x');
        if ($file === false || ! chmod($local, 0600)) {
            throw $this->failed();
        }
        fclose($file);
        $remote = "/tmp/orbit-transfer-{$transferId}-{$entry['attempt']}-{$app}-restore.tar";
        $store = AnnotatorEndpoint::store($capture->instanceId, $app);
        $owner = "{$transferId}:{$entry['attempt']}:{$capture->instanceId}:{$app}";
        $journal = $transfer->app_journal ?? [];
        $staged = $store.'.transfer-'.$transferId.'-'.$entry['attempt'];
        $receipt = $staged.'.owner';
        $journal[$app]['annotator'] = [...($journal[$app]['annotator'] ?? $entry), 'restored_store' => $store, 'staging_store' => $staged, 'ownership_receipt' => $receipt];
        $transfer->update(['app_journal' => $journal]);
        try {
            $this->copyArchive($this->scpFromRemote($source, $entry['archive'], $local), 'app-instance-transfer-download-annotator');
            $this->ssh->execute($destination, new RemoteCommand(
                ['bash', '-seu', '--', $remote],
                <<<'BASH'
                    umask 077
                    test ! -L "$1"
                    if [ -e "$1" ]; then
                        test -f "$1" && test -O "$1"
                    else
                        set -C
                        : > "$1"
                    fi
                    BASH,
            ), step: 'app-instance-transfer-reserve-annotator-archive', errorCode: 'instance.transfer_cleanup_conflict');
            $this->copyArchive($this->scpToRemote($destination, $local, $remote), 'app-instance-transfer-upload-annotator');
            $this->ssh->execute($destination, new RemoteCommand(
                ['bash', '-seu', '--', $remote, $store, $staged, $receipt, $owner],
                'sudo python3 - "$@" "$(id -u)" "$(id -g)" <<\'PY\''."\n".AnnotatorTransferProgram::restore()."\nPY",
            ), step: 'app-instance-transfer-restore-annotator', errorCode: 'instance.transfer_failed');
        } catch (RuntimeConvergenceException $exception) {
            if (trim($exception->result->stdout ?? '') === 'OWNERSHIP_CONFLICT') {
                throw new RuntimeConvergenceException('app-instance-transfer-restore-annotator', 'instance.transfer_cleanup_conflict', 'The app annotator destination has foreign ownership.', previous: $exception);
            }
            throw $exception;
        } finally {
            unlink($local);
        }
    }

    private function removePreparedStores(Node $node, InstanceTransfer $transfer, bool $rollback = true): void
    {
        foreach ($transfer->app_journal ?? [] as $app => $entry) {
            $annotator = $entry['annotator'] ?? null;
            if (! isset($annotator['restored_store'], $annotator['attempt'])) {
                continue;
            }
            $store = AnnotatorEndpoint::store($transfer->instance_id ?? throw $this->failed(), $app);
            if ($annotator['restored_store'] !== $store) {
                throw $this->failed();
            }
            $owner = "{$transfer->id}:{$annotator['attempt']}:{$transfer->instance_id}:{$app}";
            $staged = $store.'.transfer-'.$transfer->id.'-'.$annotator['attempt'];
            $receipt = $staged.'.owner';
            if (($annotator['staging_store'] ?? $staged) !== $staged || ($annotator['ownership_receipt'] ?? $receipt) !== $receipt) {
                throw $this->failed();
            }
            $this->ssh->execute($node, new RemoteCommand(
                ['bash', '-seu', '--', $store, $staged, $receipt, $owner, $rollback ? 'rollback' : 'complete'],
                'parent=$(dirname "$1"); if [ ! -e "$parent" ] && [ ! -L "$parent" ]; then exit 0; fi'."\n".
                'sudo python3 - "$1" "$2" "$3" "$4" "$(id -u)" "$5" <<\'PY\''."\n".AnnotatorTransferProgram::cleanup()."\nPY",
            ), step: 'app-instance-transfer-discard-annotator', errorCode: 'instance.transfer_failed');
        }
    }

    private function removeArchives(InstanceTransfer $transfer): void
    {
        foreach ($transfer->app_journal ?? [] as $app => $entry) {
            $annotator = $entry['annotator'] ?? null;
            if (! isset($annotator['archive'], $annotator['attempt'])) {
                continue;
            }
            $archive = "/tmp/orbit-transfer-{$transfer->id}-{$annotator['attempt']}-{$app}.tar";
            if ($annotator['archive'] !== $archive) {
                throw $this->failed();
            }
            foreach ([$transfer->source_node_id => $archive, $transfer->destination_node_id => substr($archive, 0, -4).'-restore.tar'] as $nodeId => $path) {
                $this->ssh->execute(Node::query()->findOrFail($nodeId), new RemoteCommand(
                    ['bash', '-seu', '--', $path],
                    <<<'BASH'
                        archive=$1
                        if [ -e "$archive" ] || [ -L "$archive" ]; then
                          test -f "$archive" && test ! -L "$archive" && test -O "$archive"
                          rm -f -- "$archive"
                        fi
                        BASH,
                ), step: 'app-instance-transfer-cleanup-annotator-archive', errorCode: 'instance.transfer_failed');
            }
        }
        $directory = storage_path('app/transfer-staging/'.$transfer->id);
        if (is_dir($directory)) {
            if (realpath($directory) !== $directory || ! new Filesystem()->deleteDirectory($directory)) {
                throw $this->failed();
            }
        }
    }

    private function stageArchive(Node $source, TransferSourceCapture $capture): string
    {
        $directory = storage_path('app/transfer-staging');
        $temporary = null;

        try {
            new Filesystem()->ensureDirectoryExists($directory, 0700);
            $temporary = tempnam($directory, 'orbit-transfer-');

            // tempnam can fall back to the system temporary directory. Never stage there.
            if (! is_string($temporary) || dirname($temporary) !== realpath($directory) || ! chmod($temporary, 0600)) {
                throw $this->failed();
            }
        } catch (Throwable) {
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }

            throw $this->stagingFailed('app-instance-transfer-stage');
        }

        try {
            $this->copyArchive(
                $this->scpFromRemote($source, $capture->archiveIdentity, $temporary),
                'app-instance-transfer-download',
            );
        } catch (Throwable $exception) {
            if (is_file($temporary)) {
                unlink($temporary);
            }

            throw $exception;
        }

        return $temporary;
    }

    private function uploadArchive(
        Node $destination,
        string $archive,
        StoragePath $path,
        TransferSourceCapture $capture,
    ): void {
        $instance = Instance::query()->with('project')->findOrFail($capture->instanceId);
        $environmentFiles = array_merge(...array_map(static fn (array $app): array => [ApplicationDirectory::resolvePath($path->value, $app['path']).'/.env', ApplicationDirectory::resolvePath($path->value, $app['path']).'/.env.testing'], $instance->effectiveApps()));
        $remoteArchive = "/tmp/orbit-transfer-{$capture->instanceId}.tar";
        $this->copyArchive(
            $this->scpToRemote($destination, $archive, $remoteArchive),
            'app-instance-transfer-upload',
        );

        $this->ssh->execute(
            $destination,
            new RemoteCommand(
                arguments: [
                    'bash',
                    '-seu',
                    '--',
                    $remoteArchive,
                    $path->value,
                    $capture->head,
                    $capture->branch ?? '',
                    $capture->detached ? '1' : '0',
                    ...$environmentFiles,
                ],
                input: $this->materializeScript(),
            ),
            step: 'app-instance-transfer-materialize',
            errorCode: 'instance.transfer_failed',
        );
    }

    /** @param non-empty-list<string> $arguments */
    private function copyArchive(array $arguments, string $step): void
    {
        try {
            $result = $this->processes->run(new ProcessInvocation($arguments, maxOutputBytes: 256));
        } catch (Throwable) {
            throw $this->stagingFailed($step);
        }

        if (! $result->succeeded() || $result->truncated) {
            throw $this->stagingFailed($step, $result->exitCode);
        }
    }

    private function stagingFailed(string $step, ?int $exitCode = null): ResourceOperationException
    {
        Log::warning('Instance transfer archive staging failed.', [
            'step' => $step,
            'exit_code' => $exitCode,
        ]);

        return $this->failed();
    }

    /** @return non-empty-list<string> */
    private function scpFromRemote(Node $node, string $remote, string $local): array
    {
        return $this->scpArguments($node, $this->remotePath($node, $remote), $local);
    }

    /** @return non-empty-list<string> */
    private function scpToRemote(Node $node, string $local, string $remote): array
    {
        return $this->scpArguments($node, $local, $this->remotePath($node, $remote));
    }

    /** @return non-empty-list<string> */
    private function scpArguments(Node $node, string $source, string $target): array
    {
        return [
            'scp',
            '-o',
            'BatchMode=yes',
            '-o',
            'IdentitiesOnly=yes',
            '-o',
            'StrictHostKeyChecking=yes',
            '-i',
            $this->keys->privateKeyPath(),
            '-o',
            'UserKnownHostsFile='.$this->knownHosts->path(),
            $source,
            $target,
        ];
    }

    private function remotePath(Node $node, string $path): string
    {
        $host = $node->wireguard_ip;
        $user = $node->user;

        if (! is_string($host) || filter_var($host, FILTER_VALIDATE_IP) === false) {
            throw $this->failed();
        }

        $formattedHost = str_contains($host, ':') ? "[{$host}]" : $host;

        return "{$user}@{$formattedHost}:{$path}";
    }

    /** @return array{head: string, branch: string, detached: string, archive: string, common: string, refs: string} */
    private function facts(string $stdout): array
    {
        $facts = [];

        foreach (explode("\n", trim($stdout)) as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $facts[$key] = $value;
        }

        foreach (['head', 'branch', 'detached', 'archive', 'common', 'refs'] as $required) {
            if (! is_string($facts[$required] ?? null)) {
                throw $this->failed();
            }
        }

        return $facts;
    }

    private function captureScript(): string
    {
        return WorkspaceGit::bashPreamble().<<<'BASH'
            source=$1
            layout=$2
            sqlite_source=$3
            shift 3
            for environment in "$@"; do
              case "$environment" in "$source"/*) ;; *) exit 20 ;; esac
              if [ -e "$environment" ] || [ -L "$environment" ]; then
                test -f "$environment" && test ! -L "$environment"
                test "$(realpath -e -- "$environment")" = "$environment"
                chmod o-rwx -- "$environment"
              fi
            done
            archive="/tmp/orbit-transfer-$(basename "$source")-$$.tar"
            cd -- "$source"
            head=$(git rev-parse HEAD)
            branch=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || true)
            detached=0
            if [ "$branch" = "HEAD" ]; then
              detached=1
              branch=""
            fi
            common=""
            if [ -f .git ]; then
              common=$(git rev-parse --git-common-dir)
            fi
            refs=$(git for-each-ref --format='%(refname:short)' refs/heads)
            exclusions=()
            if [ -n "$sqlite_source" ]; then
              case "$sqlite_source" in
                "$source"/*)
                  relative="./${sqlite_source#"$source"/}"
                  exclusions=(--no-wildcards --anchored "--exclude=$relative" "--exclude=$relative-wal" "--exclude=$relative-shm")
                  ;;
                *) exit 20 ;;
              esac
            fi
            if [ "$layout" = "worktree" ]; then
              git bundle create "$archive.bundle" HEAD
              tar --exclude=.git "${exclusions[@]}" -cf "$archive" .
              tar -rf "$archive" -C "$(dirname "$archive")" "$(basename "$archive.bundle")"
              rm -f -- "$archive.bundle"
            else
              tar "${exclusions[@]}" -cf "$archive" .
            fi
            printf 'head=%s\nbranch=%s\ndetached=%s\narchive=%s\ncommon=%s\nrefs=%s\n' \
              "$head" "$branch" "$detached" "$archive" "$common" "$refs"
            BASH;
    }

    private function materializeScript(): string
    {
        return WorkspaceGit::bashPreamble().WorkspaceGit::workerPreamble(TaskWorkerUser::name()).'transfer_worker='.escapeshellarg(TaskWorkerUser::name() ?? '')."\n".<<<'BASH'
            archive=$1
            destination=$2
            head=$3
            branch=$4
            detached=$5
            mkdir -p -- "$(dirname "$destination")"
            mkdir -- "$destination"
            tar -xf "$archive" -C "$destination"
            rm -f -- "$archive"
            cd -- "$destination"
            if [ ! -d .git ]; then
              git init --quiet
              if [ -n "$transfer_worker" ]; then
                worker_uid=$(id -u "$transfer_worker")
                test "$worker_uid" != 0
                test "$worker_uid" != "$(id -u)"
                managed_user=$(id -un)
                find -P "$destination" -type d -exec setfacl -m "d:u:$transfer_worker:rwX,d:u:$managed_user:rwX" -- {} +
                setfacl -R -P -m "u:$transfer_worker:rwX,u:$managed_user:rwX" -- "$destination"
                setfacl -m "u:$transfer_worker:r--" -- "$destination/.git/config"
                find -P "$destination/.git/hooks" -type d -exec setfacl -m "u:$transfer_worker:r-X,d:u:$transfer_worker:r-X" -- {} +
                find -P "$destination/.git/hooks" -type f -exec setfacl -m "u:$transfer_worker:r-X" -- {} +
              fi
              if [ -f ./*.bundle ]; then
                bundle=$(echo ./*.bundle)
                workspace_git -C "$destination" fetch --quiet "$bundle" HEAD
                rm -f -- "$bundle"
              fi
              if [ "$detached" = "1" ] || [ -z "$branch" ]; then
                workspace_git -C "$destination" update-ref --no-deref HEAD "$head"
              else
                workspace_git -C "$destination" symbolic-ref HEAD "refs/heads/$branch"
                workspace_git -C "$destination" update-ref HEAD "$head"
              fi
              # Populate the index without replacing dirty files or restoring excluded SQLite files.
              workspace_git -C "$destination" reset --mixed --quiet "$head"
            fi
            # Other local users, the Node agent included, never read an Instance's environment (ADR 0151).
            shift 5
            for environment in "$@"; do
              case "$environment" in "$destination"/*) ;; *) exit 20 ;; esac
              if [ -e "$environment" ] || [ -L "$environment" ]; then
                test -f "$environment" && test ! -L "$environment"
                test "$(realpath -e -- "$environment")" = "$environment"
                chmod o-rwx -- "$environment"
              fi
            done
            BASH;
    }

    private function cleanupScript(): string
    {
        return <<<'BASH'
            source=$1
            layout=$2
            common=$3
            shift 3
            for annotator_store in "$@"; do
              if [ -e "$annotator_store" ] || [ -L "$annotator_store" ]; then
                test -d "$annotator_store" && test ! -L "$annotator_store"
                test "$(realpath -e -- "$annotator_store")" = "$annotator_store"
                test -O "$annotator_store"
                rm -rf -- "$annotator_store"
              fi
            done
            if [ ! -e "$source" ] && [ ! -L "$source" ]; then
              exit 0
            fi
            if [ -n "$common" ] && [ "$source" = "$common" ]; then
              exit 20
            fi
            rm -rf -- "$source"
            BASH;
    }

    private function failed(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'instance.transfer_failed',
            message: 'The Instance source transfer failed safely.',
            status: 409,
        );
    }
}
