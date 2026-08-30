<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/**
 * The receipt of one exact attempt release: what was removed, what was already
 * gone, and what was verified absent afterwards.
 *
 * @mago-expect lint:cyclomatic-complexity,excessive-parameter-list Exact evidence is validated at construction.
 */
final readonly class ReleaseResult
{
    public const array KEYS = [
        'state',
        'operation_id',
        'evidence_id',
        'issue',
        'attempt_id',
        'purpose',
        'released',
        'already_absent',
        'verified_absent',
        'networks_reaped',
        'released_at',
    ];

    /** Receipts written before the orphan network sweep existed carry no `networks_reaped`. */
    private const array LEGACY_KEYS = [
        'state',
        'operation_id',
        'evidence_id',
        'issue',
        'attempt_id',
        'purpose',
        'released',
        'already_absent',
        'verified_absent',
        'released_at',
    ];

    /**
     * @param list<string> $released
     * @param list<string> $alreadyAbsent
     * @param list<string> $verifiedAbsent
     * @param list<string> $networksReaped Orphaned harness networks the release sweep deleted.
     */
    public function __construct(
        public string $operationId,
        public string $evidenceId,
        public string $issue,
        public AttemptId $attempt,
        public AttemptPurpose $purpose,
        public array $released,
        public array $alreadyAbsent,
        public array $verifiedAbsent,
        public string $releasedAt,
        public array $networksReaped = [],
    ) {
        if (
            preg_match('/\A[a-f0-9]{32}\z/D', $operationId) !== 1
            || preg_match('/\A[a-f0-9]{32}\z/D', $evidenceId) !== 1
        ) {
            throw new InvalidArgumentException('The release evidence IDs are invalid.');
        }
        TopologyTarget::assertIssue($issue);
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $releasedAt) !== 1) {
            throw new InvalidArgumentException('The release timestamp is invalid.');
        }
    }

    public static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    /** @param list<string> $networksReaped */
    public function withNetworksReaped(array $networksReaped): self
    {
        return new self(
            $this->operationId,
            $this->evidenceId,
            $this->issue,
            $this->attempt,
            $this->purpose,
            $this->released,
            $this->alreadyAbsent,
            $this->verifiedAbsent,
            $this->releasedAt,
            $networksReaped,
        );
    }

    /**
     * @return array{state:string,operation_id:string,evidence_id:string,issue:string,attempt_id:string,purpose:string,released:list<string>,already_absent:list<string>,verified_absent:list<string>,networks_reaped:list<string>,released_at:string}
     */
    public function toArray(): array
    {
        return [
            'state' => 'released',
            'operation_id' => $this->operationId,
            'evidence_id' => $this->evidenceId,
            'issue' => $this->issue,
            'attempt_id' => $this->attempt->value,
            'purpose' => $this->purpose->value,
            'released' => $this->released,
            'already_absent' => $this->alreadyAbsent,
            'verified_absent' => $this->verifiedAbsent,
            'networks_reaped' => $this->networksReaped,
            'released_at' => $this->releasedAt,
        ];
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        $keys = array_keys($value);
        if ($keys === self::LEGACY_KEYS) {
            $value = [
                ...array_slice($value, 0, -1, true),
                'networks_reaped' => [],
                'released_at' => $value['released_at'],
            ];
        }
        if (
            array_keys($value) !== self::KEYS
            || $value['state'] !== 'released'
            || ! is_string($value['operation_id'])
            || ! is_string($value['evidence_id'])
            || ! is_string($value['issue'])
            || ! is_string($value['attempt_id'])
            || ! is_string($value['purpose'])
            || ! is_array($value['released'])
            || ! is_array($value['already_absent'])
            || ! is_array($value['verified_absent'])
            || ! is_array($value['networks_reaped'])
            || ! is_string($value['released_at'])
        ) {
            throw new InvalidArgumentException('The release evidence schema is invalid.');
        }

        $purpose = AttemptPurpose::tryFrom($value['purpose']);
        if ($purpose === null) {
            throw new InvalidArgumentException('The release evidence schema is invalid.');
        }

        try {
            $attempt = new AttemptId($value['attempt_id']);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException('The release evidence schema is invalid.', previous: $exception);
        }

        return new self(
            $value['operation_id'],
            $value['evidence_id'],
            $value['issue'],
            $attempt,
            $purpose,
            self::stringList($value['released']),
            self::stringList($value['already_absent']),
            self::stringList($value['verified_absent']),
            $value['released_at'],
            self::stringList($value['networks_reaped']),
        );
    }

    /**
     * @param array<array-key, mixed> $value
     * @return list<string>
     */
    private static function stringList(array $value): array
    {
        $list = [];
        /** @mago-expect analysis:mixed-assignment Serialized input is validated one item at a time. */
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new InvalidArgumentException('The release evidence schema is invalid.');
            }
            $list[] = $item;
        }

        return $list;
    }
}
