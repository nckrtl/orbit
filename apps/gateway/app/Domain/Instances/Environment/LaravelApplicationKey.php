<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Models\Instance;

/**
 * Supplies a usable Laravel APP_KEY: the Instance's non-empty stored key, or a new random
 * 32-byte key for the default AES-256-CBC cipher. It never runs application code.
 */
final class LaravelApplicationKey
{
    public static function stored(Instance $instance): ?string
    {
        $stored = $instance->environmentValues()->where('env_key', 'APP_KEY')->first()?->env_value;

        return is_string($stored) && $stored !== '' ? $stored : null;
    }

    public static function generate(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }

    public static function storedOrGenerated(Instance $instance): string
    {
        return self::stored($instance) ?? self::generate();
    }
}
