<?php

declare(strict_types=1);

namespace App\Support\Console;

final class DeploymentOutput
{
    /** @param string|array<string, mixed> $value */
    public static function encode(string|array $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES,
        );
    }
}
