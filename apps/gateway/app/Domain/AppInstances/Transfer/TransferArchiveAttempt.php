<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Transfer;

use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstanceTransfer;
use Illuminate\Support\Str;

/**
 * @phpstan-type ArchiveReceipt array{root: string, workspace: string, archive: string, bundle: string}
 * @phpstan-type ArchivePlacement array{node_id: int, execution_user: string, private_root: string, workspace_name: string, receipt: ArchiveReceipt|null}
 */
final readonly class TransferArchiveAttempt
{
    /**
     * @param  ArchivePlacement  $source
     * @param  ArchivePlacement  $destination
     * @param  list<'source'|'destination'>  $cleanupPending
     */
    private function __construct(
        public string $id,
        public string $transferId,
        public array $source,
        public array $destination,
        public array $cleanupPending,
    ) {}

    public static function create(
        AppInstanceTransfer $transfer,
        ManagedUserAccount $source,
        ManagedUserAccount $destination,
    ): self {
        $id = (string) Str::uuid();

        return self::fromArray([
            'version' => 1,
            'id' => $id,
            'transfer_id' => $transfer->id,
            'source' => self::placement($transfer->source_node_id, $source, $id),
            'destination' => self::placement($transfer->destination_node_id, $destination, $id),
            'cleanup_pending' => ['source', 'destination'],
        ], $transfer);
    }

    /** @param array<string, mixed> $value */
    public static function fromArray(array $value, AppInstanceTransfer $transfer): self
    {
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['cleanup_pending', 'destination', 'id', 'source', 'transfer_id', 'version']
            || $value['version'] !== 1
            || ! is_string($value['id']) || ! Str::isUuid($value['id'])
            || $value['transfer_id'] !== $transfer->id
            || ! is_array($value['cleanup_pending'])
            || ! array_is_list($value['cleanup_pending'])
            || ! in_array($value['cleanup_pending'], [[], ['source'], ['destination'], ['source', 'destination']], true)) {
            self::invalid();
        }

        $source = self::validatePlacement($value['source'], $transfer->source_node_id, $value['id']);
        $destination = self::validatePlacement($value['destination'], $transfer->destination_node_id, $value['id']);

        /** @var list<'source'|'destination'> $pending */
        $pending = $value['cleanup_pending'];

        return new self($value['id'], $transfer->id, $source, $destination, $pending);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'version' => 1,
            'id' => $this->id,
            'transfer_id' => $this->transferId,
            'source' => $this->source,
            'destination' => $this->destination,
            'cleanup_pending' => $this->cleanupPending,
        ];
    }

    /**
     * @param  'source'|'destination'  $side
     * @return ArchivePlacement
     */
    public function location(string $side): array
    {
        return $side === 'source' ? $this->source : $this->destination;
    }

    /** @param 'source'|'destination' $side */
    public function archivePath(string $side): string
    {
        $placement = $this->location($side);

        return $placement['private_root'].'/'.$placement['workspace_name'].'.work/archive.tar';
    }

    /** @param list<'source'|'destination'> $pending */
    public function withCleanupPending(array $pending): self
    {
        return new self($this->id, $this->transferId, $this->source, $this->destination, $pending);
    }

    /** @param 'source'|'destination' $side */
    public function withReceipt(string $side, mixed $receipt): self
    {
        $placement = [...$this->location($side), 'receipt' => self::validateReceipt($receipt)];

        return new self(
            $this->id,
            $this->transferId,
            $side === 'source' ? $placement : $this->source,
            $side === 'destination' ? $placement : $this->destination,
            $this->cleanupPending,
        );
    }

    /** @return ArchivePlacement */
    private static function placement(int $nodeId, ManagedUserAccount $account, string $id): array
    {
        return [
            'node_id' => $nodeId,
            'execution_user' => $account->user,
            'private_root' => $account->home.'/.orbit/transfer-archives',
            'workspace_name' => $id,
            'receipt' => null,
        ];
    }

    /** @return ArchivePlacement */
    private static function validatePlacement(mixed $value, int $nodeId, string $id): array
    {
        if (! is_array($value)) {
            self::invalid();
        }
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['execution_user', 'node_id', 'private_root', 'receipt', 'workspace_name']
            || $value['node_id'] !== $nodeId
            || $nodeId < 1
            || ! is_string($value['execution_user'])
            || preg_match('/\A[a-z_][a-z0-9_-]*\$?\z/D', $value['execution_user']) !== 1
            || ! is_string($value['private_root'])
            || ! StoragePath::tryParse($value['private_root']) instanceof StoragePath
            || ! str_ends_with($value['private_root'], '/.orbit/transfer-archives')
            || $value['workspace_name'] !== $id) {
            self::invalid();
        }

        return [
            'node_id' => $nodeId,
            'execution_user' => $value['execution_user'],
            'private_root' => $value['private_root'],
            'workspace_name' => $id,
            'receipt' => $value['receipt'] === null ? null : self::validateReceipt($value['receipt']),
        ];
    }

    /** @return ArchiveReceipt */
    private static function validateReceipt(mixed $value): array
    {
        if (! is_array($value)) {
            self::invalid();
        }
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['archive', 'bundle', 'root', 'workspace']) {
            self::invalid();
        }
        foreach ($value as $identity) {
            if (! is_string($identity) || preg_match('/\A[0-9]+:[0-9]+\z/D', $identity) !== 1) {
                self::invalid();
            }
        }

        return [
            'root' => $value['root'],
            'workspace' => $value['workspace'],
            'archive' => $value['archive'],
            'bundle' => $value['bundle'],
        ];
    }

    private static function invalid(): never
    {
        throw new ResourceOperationException(
            'instance.transfer_archive_cleanup_incomplete',
            'The recorded transfer archive identity is unavailable. Retain it for recovery.',
            409,
        );
    }
}
