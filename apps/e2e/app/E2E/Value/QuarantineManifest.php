<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/**
 * @mago-expect lint:cyclomatic-complexity Serialized quarantine state validates every exact nested identity.
 * @mago-expect analysis:impossible-type-comparison Runtime serialized input can violate PHPDoc nested shapes.
 * @mago-expect analysis:invalid-array-element-key The target kind is checked before inventory validation.
 * @mago-expect analysis:less-specific-argument The observed resource is fully checked before inventory validation.
 */
final readonly class QuarantineManifest
{
    /**
     * @param array{path: string, sha256: string, mode: int, filesystem_type: string} $freezeEvidence
     * @param list<array<string, mixed>> $targets
     * @param array<string, list<array<string, mixed>>> $preserved
     * @mago-expect lint:excessive-parameter-list Every reviewed quarantine fact is explicit and immutable.
     */
    public function __construct(
        public string $inventorySha256,
        public array $freezeEvidence,
        public array $targets,
        public array $preserved,
        public string $quarantinedAt,
        public string $deleteAfter,
    ) {
        if (
            preg_match('/\A[a-f0-9]{64}\z/', $inventorySha256) !== 1
            || array_keys($freezeEvidence) !== ['path', 'sha256', 'mode', 'filesystem_type']
            || preg_match('/\A[a-f0-9]{64}\z/', $freezeEvidence['sha256'] ?? '') !== 1
            || ($freezeEvidence['mode'] ?? null) !== 0600
            || ($freezeEvidence['filesystem_type'] ?? null) !== 'file'
            || ! is_string($freezeEvidence['path'] ?? null)
            || $targets === []
            || ! array_is_list($targets)
        ) {
            throw new InvalidArgumentException('The quarantine manifest is invalid.');
        }
        $quarantineTime = \DateTimeImmutable::createFromFormat(DATE_ATOM, $quarantinedAt);
        if (
            $quarantineTime === false
            || $quarantineTime->format(DATE_ATOM) !== $quarantinedAt
            || $quarantineTime->modify('+7 days')->format(DATE_ATOM) !== $deleteAfter
        ) {
            throw new InvalidArgumentException('The quarantine delete_after must be exactly seven days later.');
        }
        $seen = [];
        $lastPosition = -1;
        $lastIdentity = null;
        foreach ($targets as $target) {
            if (
                ! is_array($target)
                || array_is_list($target)
                || array_keys($target) !== [
                    'kind',
                    'identity',
                    'original_status',
                    'metadata',
                    'dependencies',
                    'recovery',
                    'observed',
                    'observed_sha256',
                    'result',
                ]
            ) {
                throw new InvalidArgumentException('Each quarantine target must contain the exact nested schema.');
            }
            $kind = $target['kind'] ?? null;
            $identity = $target['identity'] ?? null;
            $key = is_string($kind) && is_string($identity) ? $kind."\0".$identity : '';
            $position = is_string($target['kind'] ?? null)
                ? array_search($target['kind'], RetirementInventory::CANDIDATE_KINDS, true)
                : false;
            $observed = $target['observed'] ?? null;
            if (
                ! is_int($position)
                || ! is_string($kind)
                || $position < $lastPosition
                || ! is_string($identity)
                || $position === $lastPosition
                && $lastIdentity !== null
                && strcmp($lastIdentity, $identity) >= 0
                || isset($seen[$key])
                || ! is_array($observed)
                || ! is_string($target['observed_sha256'] ?? null)
                || ! is_array($target['metadata'] ?? null)
                || ! is_array($target['dependencies'] ?? null)
                || ! is_array($target['recovery'] ?? null)
                || ! in_array($target['result'] ?? null, ['stopped', 'unchanged'], true)
                || ! hash_equals($target['observed_sha256'], hash('sha256', json_encode(
                    $observed,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                )))
            ) {
                throw new InvalidArgumentException('The quarantine targets are invalid or duplicated.');
            }
            foreach ($target['recovery'] as $command) {
                if (! is_string($command) || $command === '') {
                    throw new InvalidArgumentException('Every quarantine recovery command must be a string.');
                }
            }
            $observedIdentity = $observed['identity'] ?? $observed['name'] ?? $observed['path'] ?? null;
            if ($observedIdentity !== $target['identity']) {
                throw new InvalidArgumentException('The quarantine target identity does not match its observation.');
            }
            if (
                ($target['original_status'] ?? null) !== ($observed['status'] ?? null)
                || $target['metadata'] !== ($observed['metadata'] ?? [])
                || $target['dependencies'] !== ($observed['dependencies'] ?? [])
            ) {
                throw new InvalidArgumentException(
                    'The quarantine target status, metadata, or dependencies do not match.',
                );
            }
            RetirementInventory::assertLegacyCandidate($kind, $observed);
            new RetirementInventory([$kind => [$observed]], [], $quarantinedAt);
            $seen[$key] = true;
            $lastPosition = $position;
            $lastIdentity = $identity;
        }
        new RetirementInventory([], $preserved, $quarantinedAt);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'version' => 1,
            'inventory_sha256' => $this->inventorySha256,
            'freeze_evidence' => $this->freezeEvidence,
            'targets' => $this->targets,
            'preserved' => $this->preserved,
            'quarantined_at' => $this->quarantinedAt,
            'delete_after' => $this->deleteAfter,
        ];
    }

    /** @param array<string, mixed> $value */
    public static function fromArray(array $value): self
    {
        if (
            array_keys($value) !== [
                'version',
                'inventory_sha256',
                'freeze_evidence',
                'targets',
                'preserved',
                'quarantined_at',
                'delete_after',
            ]
        ) {
            throw new InvalidArgumentException('The quarantine manifest has unknown or missing fields.');
        }
        foreach (['inventory_sha256', 'quarantined_at', 'delete_after'] as $key) {
            if (! is_string($value[$key] ?? null)) {
                throw new InvalidArgumentException('The quarantine manifest is invalid.');
            }
        }
        if (
            ($value['version'] ?? null) !== 1
            || ! is_array($value['freeze_evidence'] ?? null)
            || ! is_array($value['targets'] ?? null)
            || ! is_array($value['preserved'] ?? null)
        ) {
            throw new InvalidArgumentException('The quarantine manifest is invalid.');
        }

        $inventorySha256 = $value['inventory_sha256'];
        /** @var array{path: string, sha256: string, mode: int, filesystem_type: string} $freezeEvidence */
        $freezeEvidence = $value['freeze_evidence'];
        $quarantinedAt = $value['quarantined_at'];
        $deleteAfter = $value['delete_after'];
        assert(
            is_string($inventorySha256) && is_string($quarantinedAt) && is_string($deleteAfter),
        );
        /** @var list<array<string, mixed>> $targets */ $targets = $value['targets'];
        /** @var array<string, list<array<string, mixed>>> $preserved */ $preserved = $value['preserved'];

        return new self(
            $inventorySha256,
            $freezeEvidence,
            $targets,
            $preserved,
            $quarantinedAt,
            $deleteAfter,
        );
    }

    public function sha256(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
