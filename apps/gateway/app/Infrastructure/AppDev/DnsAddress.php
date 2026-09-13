<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

final readonly class DnsAddress
{
    public static function normalize(string $address): ?string
    {
        $binary = inet_pton($address);
        if ($binary === false) {
            return null;
        }

        $normalized = inet_ntop($binary);

        return $normalized === false ? null : $normalized;
    }
}
