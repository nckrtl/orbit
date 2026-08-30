<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

final readonly class RefreshResult
{
    public const array STATES = ['unchanged', 'promoted', 'failed'];

    public function __construct(
        public string $state,
        public string $operationId,
        public ?string $generationId = null,
        public ?string $error = null,
    ) {
        if (
            ! in_array($state, self::STATES, true)
            || preg_match('/\A[0-9a-f]{32}\z/D', $operationId) !== 1
            || $generationId !== null
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D', $generationId) !== 1
            || ($state === 'failed') !== ($error !== null)
        ) {
            throw new InvalidArgumentException('The refresh result is invalid.');
        }
    }

    /** @return array{state:string,operation_id:string,generation_id:?string,error:?string} */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'operation_id' => $this->operationId,
            'generation_id' => $this->generationId,
            'error' => $this->error,
        ];
    }

    public function successful(): bool
    {
        return in_array($this->state, ['unchanged', 'promoted'], true);
    }
}
