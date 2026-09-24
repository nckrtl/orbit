<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/**
 * One long-lived process on a guest, run as the transient systemd unit
 * `orbit-e2e-{name}.service`. `spawn` returns as soon as systemd starts it, the
 * journal keeps its output, and `kill` stops it. `exec` cannot do this: Incus waits
 * for every process of an exec session, so a backgrounded child holds it open.
 */
final readonly class GuestProcess
{
    private const string NAME_PATTERN = '/\A[a-z0-9][a-z0-9-]{0,39}\z/';

    public string $unit;

    public function __construct(public string $name)
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(
                'The process name must be 1 to 40 lowercase letters, digits, or hyphens, starting with a letter or digit.',
            );
        }
        $this->unit = "orbit-e2e-{$name}.service";
    }

    /**
     * The command that starts the unit with the environment `exec` gives the orbit user.
     *
     * @param  list<string>  $argv
     * @return list<string>
     */
    public function spawnArgv(array $argv): array
    {
        if ($argv === [] || str_starts_with($argv[0], '-')) {
            throw new InvalidArgumentException('The spawned command must start with a program.');
        }

        return [
            'sudo',
            'systemd-run',
            '--quiet',
            '--collect',
            "--unit={$this->unit}",
            '--uid=orbit',
            '--gid=orbit',
            '--working-directory=/home/orbit',
            '--setenv=HOME=/home/orbit',
            '--setenv=ORBIT_HOME=/home/orbit/.orbit',
            '--setenv=DB_DATABASE=/home/orbit/.orbit/gateway.sqlite',
            '--property=TimeoutStopSec=10',
            '--',
            ...$argv,
        ];
    }

    /**
     * The journal of the unit, with precise timestamps, including runs that already ended.
     *
     * @return list<string>
     */
    public function logsArgv(?string $since = null, ?int $lines = null): array
    {
        if ($since !== null && preg_match('/\A[0-9A-Za-z :.+-]{1,40}\z/', $since) !== 1) {
            throw new InvalidArgumentException('--since must be a journalctl time such as "2026-09-24 06:40:00" or "-5min".');
        }
        if ($lines !== null && ($lines < 1 || $lines > 100_000)) {
            throw new InvalidArgumentException('--lines must be from 1 to 100000.');
        }

        return [
            'sudo',
            'journalctl',
            '--no-pager',
            '--output=short-iso-precise',
            "--unit={$this->unit}",
            ...($since === null ? [] : ["--since={$since}"]),
            ...($lines === null ? [] : ["--lines={$lines}"]),
        ];
    }

    /** @return list<string> */
    public function killArgv(): array
    {
        return ['sudo', 'systemctl', 'stop', $this->unit];
    }
}
