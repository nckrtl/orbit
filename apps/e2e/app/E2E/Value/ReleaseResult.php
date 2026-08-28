<?php

declare(strict_types=1);

namespace App\E2E\Value;

final readonly class ReleaseResult
{
    /**
     * @param list<string> $released
     * @param list<string> $alreadyAbsent
     */
    public function __construct(
        public string $operationId,
        public string $evidenceId,
        public array $released,
        public array $alreadyAbsent,
    ) {}

    /** @return array{state:string,operation_id:string,evidence_id:string,released:list<string>,already_absent:list<string>} */
    public function toArray(): array
    {
        return [
            'state' => 'released',
            'operation_id' => $this->operationId,
            'evidence_id' => $this->evidenceId,
            'released' => $this->released,
            'already_absent' => $this->alreadyAbsent,
        ];
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        if (
            array_keys($value) !== ['state', 'operation_id', 'evidence_id', 'released', 'already_absent']
            || $value['state'] !== 'released'
            || ! is_string($value['operation_id'])
            || ! is_string($value['evidence_id'])
            || ! is_array($value['released'])
            || ! is_array($value['already_absent'])
        ) {
            throw new \InvalidArgumentException('The release evidence schema is invalid.');
        }

        /** @var list<string> $released */
        $released = array_values(array_filter($value['released'], is_string(...)));
        /** @var list<string> $absent */
        $absent = array_values(array_filter($value['already_absent'], is_string(...)));
        if (count($released) !== count($value['released']) || count($absent) !== count($value['already_absent'])) {
            throw new \InvalidArgumentException('The release evidence schema is invalid.');
        }

        return new self($value['operation_id'], $value['evidence_id'], $released, $absent);
    }
}
