<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/** @mago-expect lint:excessive-parameter-list The normalized VM identity includes its validated power state and disks. */
final readonly class IncusInstance
{
    public ?string $mac;

    /**
     * @param array<string, string> $metadata
     * @param array<string, array{source:string,path:string}> $disks Extra disk devices keyed by device name; root is excluded.
     */
    public function __construct(
        public string $remote,
        public string $project,
        public string $name,
        public string $pool,
        public array $metadata = [],
        public string $status = 'STOPPED',
        public int $statusCode = 102,
        public ?string $network = null,
        ?string $mac = null,
        public array $disks = [],
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

        if ($mac !== null && preg_match('/\A[0-9a-f]{2}(?::[0-9a-f]{2}){5}\z/iD', $mac) !== 1) {
            throw new InvalidArgumentException('Invalid Incus instance MAC identity.');
        }

        $this->mac = $mac === null ? null : strtolower($mac);

        foreach ($disks as $device => $disk) {
            if (
                preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}\z/', $device) !== 1
                || $device === 'root'
                || array_keys($disk) !== ['source', 'path']
                || ! str_starts_with($disk['source'], '/')
                || ! str_starts_with($disk['path'], '/')
            ) {
                throw new InvalidArgumentException('Invalid Incus instance disk device.');
            }
        }
    }

    /** The exact disk device, or null when the instance does not carry it. */
    public function disk(string $device): ?array
    {
        return $this->disks[$device] ?? null;
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
