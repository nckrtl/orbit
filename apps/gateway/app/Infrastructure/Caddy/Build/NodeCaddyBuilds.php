<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build;

use App\Models\Node;

/**
 * Requests a Node Caddy build (ADR 0141). Every publisher commits its state change first, outside any
 * database transaction, and then requests a build for each Node whose sites changed.
 */
interface NodeCaddyBuilds
{
    /**
     * Renders the Node's whole Caddyfile from committed state and pushes it.
     *
     * @throws NodeCaddyBuildException when the build changed nothing on the Node
     */
    public function build(Node $node): NodeCaddyBuildResult;

    /**
     * Checks, without a change, that every specific address the Node's render binds exists on the Node.
     *
     * @throws NodeCaddyBuildException at stage `addresses` with the missing address
     */
    public function checkListenAddresses(Node $node): void;
}
