<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\Transfer\AppInstanceTransferSource;
use App\Domain\AppInstances\Transfer\TransferArchiveAttempt;
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

    public function prepareArchives(TransferArchiveAttempt $attempt): TransferArchiveAttempt
    {
        foreach (['source', 'destination'] as $side) {
            $node = Node::query()->findOrFail($attempt->location($side)['node_id']);
            $receipt = $this->archiveOperation($node, $attempt, $side, 'prepare');
            $attempt = $attempt->withReceipt($side, $receipt);
        }

        return $attempt;
    }

    public function capture(AppInstance $instance, TransferArchiveAttempt $attempt): TransferSourceCapture
    {
        $instance->loadMissing('node');
        $layout = AppInstanceSourceLayout::from($instance->source_layout);
        $facts = $this->facts($this->archiveOperation(
            $instance->node,
            $attempt,
            'source',
            'capture',
            ['source_path' => $instance->checkout_path, 'layout' => $layout->value],
        ));
        if ($facts['archive'] !== $attempt->archivePath('source')) {
            throw $this->failed();
        }

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
        TransferArchiveAttempt $attempt,
    ): TransferCheckout {
        $source = Node::query()->findOrFail($capture->nodeId);
        $archive = null;

        try {
            if ($capture->archiveIdentity !== $attempt->archivePath('source')
                || $capture->nodeId !== $attempt->source['node_id']
                || $source->user !== $attempt->source['execution_user']) {
                throw $this->failed();
            }
            $archive = $this->stageArchive($source, $capture);
            $this->uploadArchive($destination, $archive, $path, $capture, $attempt);
        } catch (Throwable) {
            throw $this->failed();
        } finally {
            if (is_string($archive) && is_file($archive)) {
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

    public function cleanupArchives(TransferArchiveAttempt $attempt): array
    {
        $pending = [];
        foreach ($attempt->cleanupPending as $side) {
            try {
                $node = Node::query()->findOrFail($attempt->location($side)['node_id']);
                if ($this->archiveOperation($node, $attempt, $side, 'cleanup') !== 'CLEANED') {
                    $pending[] = $side;
                }
            } catch (Throwable) {
                $pending[] = $side;
            }
        }

        return $pending;
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
        } catch (Throwable) {
            if (is_file($temporary)) {
                unlink($temporary);
            }

            throw $this->failed();
        }

        return $temporary;
    }

    private function uploadArchive(
        Node $destination,
        string $archive,
        StoragePath $path,
        TransferSourceCapture $capture,
        TransferArchiveAttempt $attempt,
    ): void {
        if ($destination->id !== $attempt->destination['node_id']
            || $destination->user !== $attempt->destination['execution_user']) {
            throw $this->failed();
        }
        $remoteArchive = $attempt->archivePath('destination');
        $upload = $this->processes->run(new ProcessInvocation(
            $this->scpToRemote($destination, $archive, $remoteArchive),
            maxOutputBytes: 256,
        ));

        if (! $upload->succeeded() || $upload->truncated) {
            throw $this->failed();
        }

        $result = $this->archiveOperation(
            $destination,
            $attempt,
            'destination',
            'materialize',
            [
                'destination_path' => $path->value,
                'head' => $capture->head,
                'branch' => $capture->branch ?? '',
                'detached' => $capture->detached,
            ],
        );
        if ($result !== 'MATERIALIZED') {
            throw $this->failed();
        }
    }

    /**
     * @param  'source'|'destination'  $side
     * @param  array<string, mixed>  $facts
     */
    private function archiveOperation(
        Node $node,
        TransferArchiveAttempt $attempt,
        string $side,
        string $operation,
        array $facts = [],
    ): mixed {
        $placement = $attempt->location($side);
        if ($node->id !== $placement['node_id'] || $node->user !== $placement['execution_user']) {
            throw $this->failed();
        }
        try {
            $result = $this->ssh->execute(
                $node,
                new RemoteCommand(
                    arguments: ['python3', '-c', TransferArchiveProgram::script(), $operation],
                    input: json_encode([
                        'id' => $attempt->id,
                        'transfer_id' => $attempt->transferId,
                        'side' => $side,
                        'placement' => $placement,
                        ...$facts,
                    ], JSON_THROW_ON_ERROR),
                    maxOutputBytes: 8192,
                ),
                step: 'app-instance-transfer-archive-'.$operation,
                errorCode: 'instance.transfer_failed',
            );
            if ($result->truncated || $result->stderr !== '') {
                throw $this->failed();
            }

            return json_decode($result->stdout, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw $this->failed();
        }
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
    private function facts(mixed $facts): array
    {
        if (! is_array($facts) || count($facts) !== 6) {
            throw $this->failed();
        }

        foreach (['head', 'branch', 'detached', 'archive', 'common', 'refs'] as $required) {
            if (! is_string($facts[$required] ?? null)) {
                throw $this->failed();
            }
        }

        return [
            'head' => $facts['head'], 'branch' => $facts['branch'], 'detached' => $facts['detached'],
            'archive' => $facts['archive'], 'common' => $facts['common'], 'refs' => $facts['refs'],
        ];
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
