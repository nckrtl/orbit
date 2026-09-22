<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\Transfer\AppInstanceTransferSource;
use App\Domain\AppInstances\Transfer\TransferArchiveAttempt;
use App\Domain\AppInstances\Transfer\TransferCheckout;
use App\Domain\AppInstances\Transfer\TransferCleanupResult;
use App\Domain\AppInstances\Transfer\TransferDestinationAttempt;
use App\Domain\AppInstances\Transfer\TransferSourceAttempt;
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

    public function prepareSource(TransferSourceAttempt $attempt): TransferSourceAttempt
    {
        return $attempt->withReceipt($this->sourceOperation($attempt, 'observe'));
    }

    public function capture(AppInstance $instance, TransferArchiveAttempt $attempt, TransferSourceAttempt $sourceAttempt): TransferSourceCapture
    {
        $instance->loadMissing('node');
        $layout = AppInstanceSourceLayout::from($instance->source_layout);
        if ($sourceAttempt->nodeId !== $instance->node_id || $sourceAttempt->sourcePath !== $instance->checkout_path
            || $sourceAttempt->layout !== $layout->value || $sourceAttempt->transferId !== $attempt->transferId
            || $sourceAttempt->phase !== 'owned') {
            throw $this->failed();
        }
        $facts = $this->facts($this->archiveOperation(
            $instance->node,
            $attempt,
            'source',
            'capture',
            ['source_path' => $instance->checkout_path, 'layout' => $layout->value, 'source_attempt' => $sourceAttempt->toArray()],
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
        TransferDestinationAttempt $destinationAttempt,
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
            if ($destinationAttempt->nodeId !== $destination->id
                || $destinationAttempt->destinationPath !== $path->value
                || $destinationAttempt->transferId !== $attempt->transferId
                || $destinationAttempt->phase !== 'owned') {
                throw $this->failed();
            }
            $this->uploadArchive($destination, $archive, $capture, $attempt, $destinationAttempt);
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

    public function prepareDestination(TransferDestinationAttempt $attempt): TransferDestinationAttempt
    {
        return $attempt->withReceipt($this->destinationOperation($attempt, 'create'));
    }

    public function discardDestination(TransferDestinationAttempt $attempt): void
    {
        if (in_array($attempt->phase, ['reserved', 'cleaned'], true)) {
            return;
        }
        if ($this->destinationOperation($attempt, 'cleanup') !== 'CLEANED') {
            throw $this->destinationFailed();
        }
    }

    public function cleanupSource(AppInstanceTransfer $transfer): TransferCleanupResult
    {
        try {
            $attempt = TransferSourceAttempt::fromArray($transfer->source_attempt, $transfer);
            if ($transfer->cutover_at === null || $attempt->phase !== 'owned'
                || $transfer->common_repository_path !== ($attempt->receipt['common_path'] ?? null)
                || $this->sourceOperation($attempt, 'cleanup') !== 'CLEANED') {
                return new TransferCleanupResult(false, true, ['source-placement']);
            }
        } catch (Throwable) {
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
        TransferSourceCapture $capture,
        TransferArchiveAttempt $attempt,
        TransferDestinationAttempt $destinationAttempt,
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
                'destination_attempt' => $destinationAttempt->toArray(),
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
                    arguments: ['python3', '-c', match ($operation) {
                        'capture' => TransferSourceProgram::definitions()."\n",
                        'materialize' => TransferDestinationProgram::definitions()."\n",
                        default => '',
                    }.TransferArchiveProgram::script(), $operation],
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

    private function destinationOperation(TransferDestinationAttempt $attempt, string $operation): mixed
    {
        try {
            $node = Node::query()->findOrFail($attempt->nodeId);
            if ($node->user !== $attempt->executionUser) {
                throw $this->destinationFailed();
            }
            $result = $this->ssh->execute(
                $node,
                new RemoteCommand(
                    arguments: ['python3', '-c', TransferDestinationProgram::script(), $operation],
                    input: json_encode($attempt->toArray(), JSON_THROW_ON_ERROR),
                    maxOutputBytes: 8192,
                ),
                step: 'app-instance-transfer-destination-'.$operation,
                errorCode: 'instance.transfer_destination_cleanup_incomplete',
            );
            if ($result->truncated || $result->stderr !== '') {
                throw $this->destinationFailed();
            }

            return json_decode($result->stdout, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw $this->destinationFailed();
        }
    }

    private function destinationFailed(): ResourceOperationException
    {
        return new ResourceOperationException(
            'instance.transfer_destination_cleanup_incomplete',
            'Transfer destination ownership or cleanup is unconfirmed. Retry the identical request.',
            409,
        );
    }

    private function sourceOperation(TransferSourceAttempt $attempt, string $operation): mixed
    {
        try {
            $node = Node::query()->findOrFail($attempt->nodeId);
            if ($node->user !== $attempt->executionUser) {
                throw $this->failed();
            }
            $result = $this->ssh->execute(
                $node,
                new RemoteCommand(
                    arguments: ['python3', '-c', TransferSourceProgram::script(), $operation],
                    input: json_encode($attempt->toArray(), JSON_THROW_ON_ERROR),
                    maxOutputBytes: 8192,
                ),
                step: 'app-instance-transfer-source-'.$operation,
                errorCode: 'instance.transfer_cleanup_incomplete',
            );
            if ($result->truncated || $result->stderr !== '') {
                throw $this->failed();
            }

            return json_decode($result->stdout, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new ResourceOperationException('instance.transfer_cleanup_incomplete', 'Captured source ownership is unconfirmed. Retain the transfer for recovery.', 409);
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

    private function failed(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'instance.transfer_failed',
            message: 'The AppInstance source transfer failed safely.',
            status: 409,
        );
    }
}
