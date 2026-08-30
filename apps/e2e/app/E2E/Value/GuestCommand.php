<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

final readonly class GuestCommand
{
    /**
     * Run a command as the `orbit` runtime account with its home, Orbit home, and
     * gateway database: the CLI profile and every guest-side Orbit state live there,
     * so a root shell would not see them. Root work inside an action uses `sudo`.
     */
    public const array ORBIT_USER_PREFIX = [
        'runuser',
        '-u',
        'orbit',
        '--',
        'env',
        '-C',
        '/home/orbit',
        'HOME=/home/orbit',
        'ORBIT_HOME=/home/orbit/.orbit',
        'DB_DATABASE=/home/orbit/.orbit/gateway.sqlite',
    ];

    /** @param list<string> $command */
    public function __construct(
        public array $command,
        public int $timeout = 60,
        public ?string $stdin = null,
    ) {
        if (
            $command === []
            || $timeout < 1
            || $stdin !== null
            && (strlen($stdin) > 1_048_576
            || str_contains($stdin, "\0")
            || ! mb_check_encoding($stdin, 'UTF-8'))
        ) {
            throw new InvalidArgumentException('Guest command and timeout must be valid.');
        }

        foreach ($command as $argument) {
            /** @mago-expect analysis:redundant-type-comparison Runtime callers can violate the PHPDoc list type. */
            if (! is_string($argument) || str_contains($argument, "\0")) {
                throw new InvalidArgumentException('Guest command arguments must be safe strings.');
            }
        }
    }

    /**
     * The exact argument vector, run as the `orbit` runtime user.
     *
     * @param list<string> $argv
     */
    public static function asOrbitUser(array $argv, int $timeout = 60, ?string $stdin = null): self
    {
        if ($argv === [] || ! self::isProgramArgument($argv[0])) {
            throw new InvalidArgumentException(
                'The orbit-user command must start with a program; the first argument cannot carry `=` or start with `-`.',
            );
        }

        return new self([...self::ORBIT_USER_PREFIX, ...$argv], $timeout, $stdin);
    }

    /** `env` would consume a leading `NAME=VALUE` or option as its own, so the program must come first. */
    public static function isProgramArgument(string $argument): bool
    {
        return $argument !== '' && ! str_contains($argument, '=') && ! str_starts_with($argument, '-');
    }
}
