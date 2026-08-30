<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Models\Node;
use App\Models\NodeRole;

interface RoleBaseline
{
    public function converge(Node $node, NodeRole $assignment): void;

    /** @mago-expect lint:no-boolean-flag-parameter The role lifecycle contract carries the explicit purge-data choice. */
    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void;

    /**
     * Removes only what lives on the Gateway, for a node Orbit cannot reach.
     *
     * Every step this omits is a step that would have run over SSH on the
     * node itself. The caller reports those as left behind.
     */
    public function removeUnreachable(Node $node, NodeRole $assignment): void;
}
