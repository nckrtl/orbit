<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy;

final readonly class CaddyGlobalOptions
{
    public static function render(): string
    {
        return <<<'CADDY'
            {
                auto_https disable_certs
            }
            CADDY.PHP_EOL;
    }
}
