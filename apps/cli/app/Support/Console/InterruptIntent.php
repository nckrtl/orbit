<?php

declare(strict_types=1);

namespace App\Support\Console;

use Closure;
use InvalidArgumentException;
use Throwable;

/** Invocation-scoped SIGINT/SIGTERM intent. The SDK must not depend on this type. */
final class InterruptIntent
{
    /** @var list<?int> */
    private static array $scopes = [null];

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public static function run(Closure $operation): mixed
    {
        self::$scopes[] = null;

        try {
            return $operation();
        } finally {
            array_pop(self::$scopes);

            if (self::$scopes === []) {
                self::$scopes = [null];
            }
        }
    }

    public static function record(int $signal): void
    {
        if ($signal !== SIGINT && $signal !== SIGTERM) {
            throw new InvalidArgumentException('Interrupt intent only records SIGINT and SIGTERM.');
        }

        self::$scopes[array_key_last(self::$scopes)] = $signal;
    }

    public static function pending(): ?int
    {
        return array_last(self::$scopes);
    }

    public static function exitStatus(): ?int
    {
        $signal = self::pending();

        return $signal === null ? null : 128 + $signal;
    }

    public static function clear(): void
    {
        self::$scopes = [null];
    }

    public static function cancellation(?Throwable $exception = null): bool
    {
        return self::pending() !== null || $exception instanceof ConsoleInterrupted;
    }

    public static function throwIfPending(): void
    {
        $signal = self::pending();

        if ($signal !== null) {
            throw new ConsoleInterrupted($signal);
        }
    }
}
