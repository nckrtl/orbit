<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/** @mago-expect lint:cyclomatic-complexity,excessive-parameter-list Exact evidence is validated at construction. */
final readonly class SourceState
{
    /** @param list<string> $overlayPaths */
    public function __construct(
        public string $hostSha,
        public string $guestSha,
        public bool $dirty = false,
        public ?string $treeHash = null,
        public array $overlayPaths = [],
        public ?string $operationId = null,
    ) {
        foreach ([$hostSha, $guestSha] as $sha) {
            if (preg_match('/\A[a-f0-9]{40}\z/D', $sha) !== 1) {
                throw new InvalidArgumentException('A source SHA is invalid.');
            }
        }

        if ($treeHash !== null && preg_match('/\A[a-f0-9]{64}\z/D', $treeHash) !== 1) {
            throw new InvalidArgumentException('The source tree hash is invalid.');
        }

        if ($dirty !== ($treeHash !== null)) {
            throw new InvalidArgumentException('Dirty source must have one tree hash.');
        }

        new DirtyOverlay($overlayPaths, $treeHash ?? str_repeat('0', 64));

        if (! $dirty && $overlayPaths !== []) {
            throw new InvalidArgumentException('Clean source cannot have overlay paths.');
        }

        if ($operationId !== null && preg_match('/\A[0-9a-f]{32}\z/D', $operationId) !== 1) {
            throw new InvalidArgumentException('The source operation ID is invalid.');
        }
    }

    /** @return array{host_sha:string,guest_sha:string,dirty:bool,tree_hash:?string,overlay_paths:list<string>,operation_id:?string} */
    public function toArray(): array
    {
        return [
            'host_sha' => $this->hostSha,
            'guest_sha' => $this->guestSha,
            'dirty' => $this->dirty,
            'tree_hash' => $this->treeHash,
            'overlay_paths' => $this->overlayPaths,
            'operation_id' => $this->operationId,
        ];
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        if (
            array_keys($value) !== ['host_sha', 'guest_sha', 'dirty', 'tree_hash', 'overlay_paths', 'operation_id']
            || ! is_string($value['host_sha'])
            || ! is_string($value['guest_sha'])
            || ! is_bool($value['dirty'])
            || $value['tree_hash'] !== null
            && ! is_string($value['tree_hash'])
            || ! is_array($value['overlay_paths'])
            || $value['operation_id'] !== null
            && ! is_string($value['operation_id'])
        ) {
            throw new InvalidArgumentException('The source state schema is invalid.');
        }

        $overlayPaths = [];
        /** @mago-expect analysis:mixed-assignment Serialized input is validated one path at a time. */
        foreach ($value['overlay_paths'] as $path) {
            if (! is_string($path)) {
                throw new InvalidArgumentException('The source state schema is invalid.');
            }
            $overlayPaths[] = $path;
        }

        return new self(
            $value['host_sha'],
            $value['guest_sha'],
            $value['dirty'],
            $value['tree_hash'],
            $overlayPaths,
            $value['operation_id'],
        );
    }
}
