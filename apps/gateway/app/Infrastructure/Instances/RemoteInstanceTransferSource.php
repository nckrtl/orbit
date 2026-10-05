<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\AppDev\AnnotatorEndpoint;
use App\Domain\AppDev\RuntimeConvergenceException;
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
        $instance->loadMissing('node');
        $layout = InstanceSourceLayout::from($instance->source_layout);
        $result = $this->ssh->execute(
            $instance->node,
            new RemoteCommand(
                arguments: ['bash', '-seu', '--', $instance->checkout_path, $layout->value, $sqliteSourcePath ?? '', ...($instance->annotator_port === null ? [] : [AnnotatorEndpoint::forInstance($instance)])],
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

    public function cleanupSource(InstanceTransfer $transfer): TransferCleanupResult
    {
        $source = Node::query()->findOrFail($transfer->source_node_id);
        $common = $transfer->common_repository_path;

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
                        ...(Instance::query()->whereKey($transfer->instance_id)->whereNotNull('annotator_port')->exists() ? [AnnotatorEndpoint::forInstance(Instance::query()->findOrFail($transfer->instance_id ?? throw $this->failed()))] : []),
                    ],
                    input: $this->cleanupScript(),
                ),
                step: 'app-instance-transfer-cleanup',
                errorCode: 'instance.transfer_cleanup_incomplete',
            );
        } catch (RuntimeConvergenceException) {
            return new TransferCleanupResult(false, true, ['source-placement']);
        }

        return new TransferCleanupResult(true, true);
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
        $environmentDirectory = $instance->source_is_laravel === true
            ? ApplicationDirectory::resolvePath($path->value, $instance->applicationPath())
            : $path->value;
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
                    $environmentDirectory.'/.env',
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
            annotator_store=${4:-}
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
            if [ -n "$annotator_store" ] && [ -d "$annotator_store" ]; then
              test ! -e .orbit/annotator
              tar --transform='s,^\./,./.orbit/annotator/,;s,^\.$,./.orbit/annotator,' -rf "$archive" -C "$annotator_store" .
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
            environment=${6:-$destination/.env}
            case "$environment" in "$destination"/*) ;; *) exit 20 ;; esac
            if [ -f "$environment" ] && [ ! -L "$environment" ]; then
              test "$(realpath -e -- "$environment")" = "$environment"
              chmod o-rwx -- "$environment"
            fi
            BASH;
    }

    private function cleanupScript(): string
    {
        return <<<'BASH'
            source=$1
            layout=$2
            common=$3
            annotator_store=${4:-}
            if [ -n "$annotator_store" ]; then
              sudo rm -rf -- "$annotator_store"
            fi
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
