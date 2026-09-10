<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withCache(
        __DIR__.'/vendor/rector/cache',
        null,
        __DIR__.'/vendor/rector',
    )
    ->withoutParallel()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/bootstrap/app.php',
        __DIR__.'/bootstrap/providers.php',
        __DIR__.'/config',
        __DIR__.'/tests',
    ])
    ->withPhpSets();
