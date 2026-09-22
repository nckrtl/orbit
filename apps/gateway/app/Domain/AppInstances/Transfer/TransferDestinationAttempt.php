<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Transfer;

use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstanceTransfer;
use Illuminate\Support\Str;

/** @phpstan-type DestinationReceipt array{root: string, parent: string, scope: string, checkout: string} */
final readonly class TransferDestinationAttempt
{
    /** @param DestinationReceipt|null $receipt */
    private function __construct(
        public string $id,
        public string $transferId,
        public int $nodeId,
        public string $executionUser,
        public string $privateRoot,
        public string $destinationPath,
        public string $phase,
        public ?array $receipt,
    ) {}

    public static function create(AppInstanceTransfer $transfer, ManagedUserAccount $account): self
    {
        return self::fromArray([
            'version' => 1,
            'id' => (string) Str::uuid(),
            'transfer_id' => $transfer->id,
            'node_id' => $transfer->destination_node_id,
            'execution_user' => $account->user,
            'private_root' => $account->home.'/.orbit/transfer-destinations',
            'destination_path' => $transfer->destination_path,
            'phase' => 'reserved',
            'receipt' => null,
        ], $transfer);
    }

    public static function fromArray(mixed $value, AppInstanceTransfer $transfer): self
    {
        if (! is_array($value)) {
            self::invalid();
        }
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['destination_path', 'execution_user', 'id', 'node_id', 'phase', 'private_root', 'receipt', 'transfer_id', 'version']
            || $value['version'] !== 1
            || ! is_string($value['id']) || ! Str::isUuid($value['id'])
            || $value['transfer_id'] !== $transfer->id
            || $value['node_id'] !== $transfer->destination_node_id || $value['node_id'] < 1
            || ! is_string($value['execution_user'])
            || preg_match('/\A[a-z_][a-z0-9_-]*\$?\z/D', $value['execution_user']) !== 1
            || ! is_string($value['private_root'])
            || ! StoragePath::tryParse($value['private_root']) instanceof StoragePath
            || ! str_ends_with($value['private_root'], '/.orbit/transfer-destinations')
            || $value['destination_path'] !== $transfer->destination_path
            || ! StoragePath::tryParse($value['destination_path']) instanceof StoragePath
            || ! in_array($value['phase'], ['reserved', 'acquiring', 'owned', 'cleaned'], true)
            || in_array($value['phase'], ['reserved', 'acquiring'], true) && $value['receipt'] !== null
            || $value['phase'] === 'owned' && $value['receipt'] === null) {
            self::invalid();
        }

        return new self(
            $value['id'], $transfer->id, $value['node_id'], $value['execution_user'],
            $value['private_root'], $value['destination_path'], $value['phase'],
            $value['receipt'] === null ? null : self::validateReceipt($value['receipt']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'version' => 1, 'id' => $this->id, 'transfer_id' => $this->transferId,
            'node_id' => $this->nodeId, 'execution_user' => $this->executionUser,
            'private_root' => $this->privateRoot, 'destination_path' => $this->destinationPath,
            'phase' => $this->phase, 'receipt' => $this->receipt,
        ];
    }

    public function acquiring(): self
    {
        if ($this->phase !== 'reserved') {
            self::invalid();
        }

        return new self($this->id, $this->transferId, $this->nodeId, $this->executionUser, $this->privateRoot, $this->destinationPath, 'acquiring', null);
    }

    public function withReceipt(mixed $receipt): self
    {
        if ($this->phase !== 'acquiring') {
            self::invalid();
        }

        return new self($this->id, $this->transferId, $this->nodeId, $this->executionUser, $this->privateRoot, $this->destinationPath, 'owned', self::validateReceipt($receipt));
    }

    public function cleaned(): self
    {
        return new self($this->id, $this->transferId, $this->nodeId, $this->executionUser, $this->privateRoot, $this->destinationPath, 'cleaned', $this->receipt);
    }

    /** @return DestinationReceipt */
    private static function validateReceipt(mixed $value): array
    {
        if (! is_array($value)) {
            self::invalid();
        }
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['checkout', 'parent', 'root', 'scope']) {
            self::invalid();
        }
        foreach ($value as $identity) {
            if (! is_string($identity) || preg_match('/\A[0-9]+:[0-9]+\z/D', $identity) !== 1) {
                self::invalid();
            }
        }

        return ['root' => $value['root'], 'parent' => $value['parent'], 'scope' => $value['scope'], 'checkout' => $value['checkout']];
    }

    private static function invalid(): never
    {
        throw new ResourceOperationException(
            'instance.transfer_destination_cleanup_incomplete',
            'The recorded transfer destination identity is unavailable. Retain it for recovery.',
            409,
        );
    }
}
