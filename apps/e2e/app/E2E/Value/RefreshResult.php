<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

final readonly class RefreshResult
{
    public const array STATES = ['unchanged', 'queued', 'promoted', 'failed'];

    public function __construct(
        public string $state,
        public string $operationId,
        public string $evidenceId,
        public ?string $generationId = null,
    ) {
        if (
            ! in_array($state, self::STATES, true)
            || preg_match('/\A[0-9a-f]{32}\z/D', $operationId) !== 1
            || preg_match('/\A[0-9a-f]{32}\z/D', $evidenceId) !== 1
            || $generationId !== null
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D', $generationId) !== 1
        ) {
            throw new InvalidArgumentException('The refresh result is invalid.');
        }
    }

    /** @return array{state:string,operation_id:string,evidence_id:string,generation_id:?string} */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'operation_id' => $this->operationId,
            'evidence_id' => $this->evidenceId,
            'generation_id' => $this->generationId,
        ];
    }
}
