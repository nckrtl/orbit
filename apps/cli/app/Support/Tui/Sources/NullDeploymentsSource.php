<?php

declare(strict_types=1);

namespace App\Support\Tui\Sources;

/** The default DeploymentsSource until the Gateway exposes deployment history. */
final class NullDeploymentsSource implements DeploymentsSource
{
    public function forInstance(int $instanceId): ?array
    {
        return null;
    }
}
