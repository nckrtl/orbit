<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/** The retry-safe outcome of one clean topology snapshot replacement installation. */
final readonly class TopologySnapshotReplacementResult
{
    public function __construct(
        public string $state,
        public string $operationId,
        public ?string $generationId,
        public string $phase,
        public ?string $error = null,
        public ?string $nextAction = null,
    ) {
        if (! in_array($state, ['installed', 'failed', 'recovery-required', 'abandoned'], true)) {
            throw new InvalidArgumentException('The topology snapshot replacement result state is invalid.');
        }
        if (preg_match('/\A[a-f0-9]{32}\z/D', $operationId) !== 1 || $phase === '') {
            throw new InvalidArgumentException('The topology snapshot replacement result identity is invalid.');
        }
        if (
            $generationId !== null
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D', $generationId) !== 1
        ) {
            throw new InvalidArgumentException('The topology snapshot replacement generation identity is invalid.');
        }
        if ($state === 'installed' && ($generationId === null || $error !== null || $nextAction !== null)) {
            throw new InvalidArgumentException('An installed topology snapshot replacement result is incomplete.');
        }
        if ($state === 'abandoned' && ($error !== null || $nextAction !== null)) {
            throw new InvalidArgumentException('An abandoned topology snapshot replacement result is invalid.');
        }
        if (in_array($state, ['failed', 'recovery-required'], true) && ($error === null || $nextAction === null)) {
            throw new InvalidArgumentException('A failed topology snapshot replacement result requires recovery details.');
        }
    }

    public function successful(): bool
    {
        return $this->state === 'installed';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'operation_id' => $this->operationId,
            'generation_id' => $this->generationId,
            'phase' => $this->phase,
            'error' => $this->error,
            'next_action' => $this->nextAction,
        ];
    }
}
