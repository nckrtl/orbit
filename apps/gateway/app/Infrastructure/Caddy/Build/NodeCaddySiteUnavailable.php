<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build;

use RuntimeException;

/**
 * A site source cannot render a site from stored state, so the build must not replace the live file.
 */
final class NodeCaddySiteUnavailable extends RuntimeException
{
    public static function wireGuardAddressMissing(string $source, string $nodeName): self
    {
        return new self("The {$source} site source needs the WireGuard IPv4 address of Node [{$nodeName}].");
    }
}
