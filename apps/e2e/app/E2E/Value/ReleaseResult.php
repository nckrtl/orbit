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
        'networks_failed',
        'released_at',
    ];

    /** Keys a receipt written before the orphan network sweep may lack; they default to an empty list. */
    private const array OPTIONAL_KEYS = ['networks_reaped', 'networks_failed'];

    /**
     * @param list<string> $released
     * @param list<string> $alreadyAbsent
     * @param list<string> $verifiedAbsent
     * @param list<string> $networksReaped Orphaned harness networks the release sweep deleted.
     * @param list<string> $networksFailed `name: message` per orphan the sweep could not delete.
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
        public array $networksFailed = [],
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

    /**
     * @param list<string> $networksReaped
     * @param list<string> $networksFailed
     */
    public function withNetworksReaped(array $networksReaped, array $networksFailed = []): self
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
            $networksFailed,
        );
    }

    /**
     * @return array{state:string,operation_id:string,evidence_id:string,issue:string,attempt_id:string,purpose:string,released:list<string>,already_absent:list<string>,verified_absent:list<string>,networks_reaped:list<string>,networks_failed:list<string>,released_at:string}
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
            'networks_failed' => $this->networksFailed,
            'released_at' => $this->releasedAt,
        ];
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        $value = self::withOptionalKeys($value);
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
            || ! is_array($value['networks_failed'])
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
            self::stringList($value['networks_failed']),
        );
    }

    /**
     * Insert each absent optional key as an empty list at its canonical position,
     * so receipts written before the sweep existed keep their exact key order.
     *
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function withOptionalKeys(array $value): array
    {
        $present = array_keys($value);
        $missing = array_diff(self::OPTIONAL_KEYS, $present);
        if ($missing === [] || array_values(array_intersect(self::KEYS, $present)) !== $present) {
            return $value;
        }
        $ordered = [];
        foreach (self::KEYS as $key) {
            if (in_array($key, $missing, true)) {
                $ordered[$key] = [];
            } elseif (array_key_exists($key, $value)) {
                $ordered[$key] = $value[$key];
            }
        }

        return $ordered;
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
