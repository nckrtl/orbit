<?php

declare(strict_types=1);

namespace App\Domain\ProxyCli;

final readonly class ProxyCliKeys
{
    public const string Lock = 'orbit:proxycli:lock';

    public const string Raw = 'orbit:proxycli:raw';

    public const string Snapshot = 'orbit:proxycli:snapshot';

    public const string BackoffPrefix = 'orbit:proxycli:backoff:';

    public static function backoff(string $target): string
    {
        return self::BackoffPrefix.$target;
    }
}
