<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build;

use App\Models\Node;

/**
 * Renders the sites one role or feature serves on a Node from committed database state only.
 */
interface NodeCaddySiteSource
{
    /** @return list<CaddySite> */
    public function sites(Node $node): array;
}
