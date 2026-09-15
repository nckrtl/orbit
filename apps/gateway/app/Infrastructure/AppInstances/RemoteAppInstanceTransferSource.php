<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\Transfer\AppInstanceTransferSource;
use App\Domain\AppInstances\Transfer\TransferCheckout;
use App\Domain\AppInstances\Transfer\TransferCleanupResult;
use App\Domain\AppInstances\Transfer\TransferSourceCapture;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\AppInstance;
use App\Models\AppInstanceTransfer;
use App\Models\Node;
use Throwable;

final readonly class RemoteAppInstanceTransferSource implements AppInstanceTransferSource
{
    public function __construct(
        private AppDevSshExecutor $ssh,
        private ProcessRunner $processes,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
    ) {}

    public function capture(AppInstance $instance): TransferSourceCapture
    {
        $instance->loadMissing('node');
        $layout = AppInstanceSourceLayout::from($instance->source_layout);
        $result = $this->ssh->execute(
            $instance->node,
            new RemoteCommand(
                arguments: ['bash', '-seu', '--', $instance->checkout_path, $layout->value],
                input: $this->captureScript(),
            ),
            step: 'app-instance-transfer-capture',
            errorCode: 'instance.transfer_failed',
        );
        $facts = $this->facts($result->stdout);

        return new TransferSourceCapture(
            appInstanceId: $instance->id,
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
            layout: AppInstanceSourceLayout::Checkout,
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

    public function cleanupSource(AppInstanceTransfer $transfer): TransferCleanupResult
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
        $temporary = tempnam(sys_get_temp_dir(), 'orbit-transfer-');

        if (! is_string($temporary)) {
            throw $this->failed();
        }

        try {
            if (! chmod($temporary, 0600)) {
                throw $this->failed();
            }

            $download = $this->processes->run(new ProcessInvocation(
                $this->scpFromRemote($source, $capture->archiveIdentity, $temporary),
                maxOutputBytes: 256,
            ));

            if (! $download->succeeded() || $download->truncated) {
                throw $this->failed();
            }
        } catch (Throwable $exception) {
            if (is_file($temporary)) {
                unlink($temporary);
            }

            throw $exception instanceof ResourceOperationException ? $exception : $this->failed();
        }

        return $temporary;
    }

    private function uploadArchive(
        Node $destination,
        string $archive,
        StoragePath $path,
        TransferSourceCapture $capture,
    ): void {
        $remoteArchive = "/tmp/orbit-transfer-{$capture->appInstanceId}.tar";
        $upload = $this->processes->run(new ProcessInvocation(
            $this->scpToRemote($destination, $archive, $remoteArchive),
            maxOutputBytes: 256,
        ));

        if (! $upload->succeeded() || $upload->truncated) {
            throw $this->failed();
        }

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
                ],
                input: $this->materializeScript(),
            ),
            step: 'app-instance-transfer-materialize',
            errorCode: 'instance.transfer_failed',
        );
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
        return <<<'BASH'
            source=$1
            layout=$2
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
            if [ "$layout" = "worktree" ]; then
              git bundle create "$archive.bundle" HEAD
              tar --exclude=.git -cf "$archive" .
              tar -rf "$archive" -C "$(dirname "$archive")" "$(basename "$archive.bundle")"
              rm -f -- "$archive.bundle"
            else
              tar -cf "$archive" .
            fi
            printf 'head=%s\nbranch=%s\ndetached=%s\narchive=%s\ncommon=%s\nrefs=%s\n' \
              "$head" "$branch" "$detached" "$archive" "$common" "$refs"
            BASH;
    }

    private function materializeScript(): string
    {
        return <<<'BASH'
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
              if [ -f ./*.bundle ]; then
                bundle=$(echo ./*.bundle)
                git fetch --quiet "$bundle" HEAD
                rm -f -- "$bundle"
              fi
              if [ "$detached" = "1" ] || [ -z "$branch" ]; then
                git checkout --quiet --detach "$head"
              else
                git checkout --quiet -B "$branch" "$head"
              fi
            fi
            BASH;
    }

    private function cleanupScript(): string
    {
        return <<<'BASH'
            source=$1
            layout=$2
            common=$3
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
            message: 'The AppInstance source transfer failed safely.',
            status: 409,
        );
    }
}
