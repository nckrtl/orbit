<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy;

trait OwnsCaddyGlobalOptions
{
    private function encodedGlobalOptions(): string
    {
        return base64_encode(CaddyGlobalOptions::render());
    }
}
