<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/** @mago-expect lint:excessive-parameter-list The normalized VM identity includes its validated power state. */
final readonly class IncusInstance
{
    /** @param array<string, string> $metadata */
    public function __construct(
        public string $remote,
        public string $project,
        public string $name,
        public string $pool,
        public array $metadata = [],
        public string $status = 'STOPPED',
        public int $statusCode = 102,
        public ?string $network = null,
    ) {
        foreach ([$remote, $project, $name, $pool] as $identity) {
            if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}\z/', $identity) !== 1) {
                throw new InvalidArgumentException('Invalid Incus instance identity.');
            }
        }

        if (! in_array([$status, $statusCode], [['RUNNING', 103], ['STOPPED', 102]], true)) {
            throw new InvalidArgumentException('Invalid Incus instance power status.');
        }

        if ($network !== null && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}\z/', $network) !== 1) {
            throw new InvalidArgumentException('Invalid Incus instance network identity.');
        }
    }

    public function isRunning(): bool
    {
        return $this->status === 'RUNNING';
    }

    public function isStopped(): bool
    {
        return $this->status === 'STOPPED';
    }
}
