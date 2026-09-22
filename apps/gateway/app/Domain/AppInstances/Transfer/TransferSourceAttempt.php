<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Transfer;

use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstanceTransfer;
use Illuminate\Support\Str;

/** @phpstan-type SourceReceipt array{root: string, parent: string, scope: string, checkout: string, common_path: string|null, common: string|null} */
final readonly class TransferSourceAttempt
{
    /** @param SourceReceipt|null $receipt */
    private function __construct(
        public string $id,
        public string $transferId,
        public int $nodeId,
        public string $executionUser,
        public string $privateRoot,
        public string $sourcePath,
        public string $layout,
        public string $phase,
        public ?array $receipt,
    ) {}

    public static function create(AppInstanceTransfer $transfer, ManagedUserAccount $account): self
    {
        return self::fromArray([
            'version' => 1, 'id' => (string) Str::uuid(), 'transfer_id' => $transfer->id,
            'node_id' => $transfer->source_node_id, 'execution_user' => $account->user,
            'private_root' => $account->home.'/.orbit/transfer-sources',
            'source_path' => $transfer->source_path, 'layout' => $transfer->source_layout->value,
            'phase' => 'reserved', 'receipt' => null,
        ], $transfer);
    }

    public static function fromArray(mixed $value, AppInstanceTransfer $transfer): self
    {
        if (! is_array($value)) {
            self::invalid();
        }
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['execution_user', 'id', 'layout', 'node_id', 'phase', 'private_root', 'receipt', 'source_path', 'transfer_id', 'version']
            || $value['version'] !== 1
            || ! is_string($value['id']) || ! Str::isUuid($value['id'])
            || $value['transfer_id'] !== $transfer->id
            || $value['node_id'] !== $transfer->source_node_id || $value['node_id'] < 1
            || ! is_string($value['execution_user']) || preg_match('/\A[a-z_][a-z0-9_-]*\$?\z/D', $value['execution_user']) !== 1
            || ! is_string($value['private_root']) || ! StoragePath::tryParse($value['private_root']) instanceof StoragePath
            || ! str_ends_with($value['private_root'], '/.orbit/transfer-sources')
            || $value['source_path'] !== $transfer->source_path || ! StoragePath::tryParse($value['source_path']) instanceof StoragePath
            || $value['layout'] !== $transfer->source_layout->value || ! in_array($value['layout'], ['checkout', 'worktree'], true)
            || ! in_array($value['phase'], ['reserved', 'acquiring', 'owned'], true)
            || ($value['phase'] === 'owned') !== ($value['receipt'] !== null)) {
            self::invalid();
        }

        return new self(
            $value['id'], $transfer->id, $value['node_id'], $value['execution_user'], $value['private_root'],
            $value['source_path'], $value['layout'], $value['phase'],
            $value['receipt'] === null ? null : self::validateReceipt($value['receipt'], $value['layout']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'version' => 1, 'id' => $this->id, 'transfer_id' => $this->transferId,
            'node_id' => $this->nodeId, 'execution_user' => $this->executionUser, 'private_root' => $this->privateRoot,
            'source_path' => $this->sourcePath, 'layout' => $this->layout, 'phase' => $this->phase, 'receipt' => $this->receipt,
        ];
    }

    public function acquiring(): self
    {
        if ($this->phase !== 'reserved') {
            self::invalid();
        }

        return new self($this->id, $this->transferId, $this->nodeId, $this->executionUser, $this->privateRoot, $this->sourcePath, $this->layout, 'acquiring', null);
    }

    public function withReceipt(mixed $receipt): self
    {
        if ($this->phase !== 'acquiring') {
            self::invalid();
        }

        return new self($this->id, $this->transferId, $this->nodeId, $this->executionUser, $this->privateRoot, $this->sourcePath, $this->layout, 'owned', self::validateReceipt($receipt, $this->layout));
    }

    /** @return SourceReceipt */
    private static function validateReceipt(mixed $value, string $layout): array
    {
        if (! is_array($value)) {
            self::invalid();
        }
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['checkout', 'common', 'common_path', 'parent', 'root', 'scope']) {
            self::invalid();
        }
        foreach (['root', 'parent', 'scope', 'checkout'] as $key) {
            if (! is_string($value[$key]) || preg_match('/\A[0-9]+:[0-9]+\z/D', $value[$key]) !== 1) {
                self::invalid();
            }
        }
        if ($layout === 'worktree') {
            if (! is_string($value['common_path']) || ! StoragePath::tryParse($value['common_path']) instanceof StoragePath
                || ! is_string($value['common']) || preg_match('/\A[0-9]+:[0-9]+\z/D', $value['common']) !== 1) {
                self::invalid();
            }
        } elseif ($value['common_path'] !== null || $value['common'] !== null) {
            self::invalid();
        }

        return $value;
    }

    private static function invalid(): never
    {
        throw new ResourceOperationException('instance.transfer_cleanup_incomplete', 'Captured source ownership is unconfirmed. Retain the transfer for recovery.', 409);
    }
}
