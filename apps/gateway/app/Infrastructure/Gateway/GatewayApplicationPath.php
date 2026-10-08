<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway;

use Illuminate\Support\Facades\Config;

/**
 * The stable path of the Gateway application, `ORBIT_GATEWAY_CHECKOUT`. On a Gateway that runs from releases it is
 * a link to the current release, while `base_path()` is the real directory of the release this process started
 * from. Units, hooks, and commit checks that must follow the next release use this path. A machine without that
 * directory, such as a development checkout, falls back to `base_path()`.
 */
final class GatewayApplicationPath
{
    public static function resolve(): string
    {
        $configured = rtrim(Config::string('orbit.gateway_checkout'), '/');

        return $configured !== '' && is_dir($configured) ? $configured : base_path();
    }
}
