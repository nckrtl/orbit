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
        __DIR__.'/src',
    ])
    ->withPhpSets();
