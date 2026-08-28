<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/**
 * @mago-expect lint:cyclomatic-complexity Serialized retirement results validate exact nested identities.
 * @mago-expect analysis:impossible-type-comparison Runtime serialized input can violate PHPDoc nested shapes.
 */
final readonly class RetirementResult
{
    /** @param list<array<string, mixed>> $deleted @param list<array<string, mixed>> $remaining @param array<string, list<array<string, mixed>>> $preserved */
    public function __construct(
        public bool $successful,
        public array $deleted,
        public array $remaining,
        public array $preserved,
        public string $quarantineSha256,
    ) {
        if (preg_match('/\A[a-f0-9]{64}\z/', $quarantineSha256) !== 1) {
            throw new InvalidArgumentException('The retirement result digest is invalid.');
        }
        if (! array_is_list($deleted) || ! array_is_list($remaining)) {
            throw new InvalidArgumentException('Retirement deleted and remaining resources must be lists.');
        }
        $seenDeleted = [];
        foreach ($deleted as $resource) {
            if (
                ! is_array($resource)
                || array_is_list($resource)
                || array_keys($resource) !== ['kind', 'identity', 'result']
            ) {
                throw new InvalidArgumentException('Each deleted retirement result must contain the exact schema.');
            }
            $kind = $resource['kind'] ?? null;
            $identity = $resource['identity'] ?? null;
            if (
                ! is_string($kind)
                || ! in_array($kind, RetirementInventory::CANDIDATE_KINDS, true)
                || ! is_string($identity)
                || $identity === ''
                || ($resource['result'] ?? null) !== 'deleted'
            ) {
                throw new InvalidArgumentException('The retirement result identity is invalid.');
            }
            $key = $kind."\0".$identity;
            if (isset($seenDeleted[$key])) {
                throw new InvalidArgumentException('The retirement result contains duplicate deleted identities.');
            }
            $seenDeleted[$key] = true;
        }
        $seenRemaining = [];
        foreach ($remaining as $resource) {
            if (
                ! is_array($resource)
                || array_is_list($resource)
                || array_keys($resource) !== ['kind', 'identity', 'result', 'reason']
            ) {
                throw new InvalidArgumentException('Each remaining retirement result must contain the exact schema.');
            }
            if (
                ! is_string($resource['kind'] ?? null)
                || ! in_array(
                    $resource['kind'],
                    array_unique([...RetirementInventory::CANDIDATE_KINDS, ...RetirementInventory::PRESERVED_KINDS]),
                    true,
                )
                || ! is_string($resource['identity'] ?? null)
                || $resource['identity'] === ''
                || ($resource['result'] ?? null) !== 'remaining'
                || ! in_array(
                    $resource['reason'] ?? null,
                    ['not_deleted', 'preserved_identity_changed', 'unexpected_legacy_identity'],
                    true,
                )
            ) {
                throw new InvalidArgumentException('The retirement result remaining identity is invalid.');
            }
            $key = $resource['kind']."\0".$resource['identity']."\0".$resource['reason'];
            if (isset($seenRemaining[$key])) {
                throw new InvalidArgumentException('The retirement result contains duplicate remaining identities.');
            }
            $seenRemaining[$key] = true;
        }
        new RetirementInventory([], $preserved, '2000-01-01T00:00:00+00:00');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'version' => 1,
            'successful' => $this->successful,
            'quarantine_sha256' => $this->quarantineSha256,
            'deleted' => $this->deleted,
            'remaining' => $this->remaining,
            'preserved' => $this->preserved,
        ];
    }

    /** @param array<string, mixed> $value */
    public static function fromArray(array $value): self
    {
        if (
            array_keys($value) !== ['version', 'successful', 'quarantine_sha256', 'deleted', 'remaining', 'preserved']
            || ($value['version'] ?? null) !== 1
            || ! is_bool($value['successful'] ?? null)
            || ! is_string($value['quarantine_sha256'] ?? null)
            || ! is_array($value['deleted'] ?? null)
            || ! is_array($value['remaining'] ?? null)
            || ! is_array($value['preserved'] ?? null)
        ) {
            throw new InvalidArgumentException('The retirement result is invalid.');
        }
        /** @var list<array<string, mixed>> $deleted */ $deleted = $value['deleted'];
        /** @var list<array<string, mixed>> $remaining */ $remaining = $value['remaining'];
        /** @var array<string, list<array<string, mixed>>> $preserved */ $preserved = $value['preserved'];

        return new self($value['successful'], $deleted, $remaining, $preserved, $value['quarantine_sha256']);
    }
}
