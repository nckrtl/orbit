<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Models\Node;

/**
 * One Gateway-rendered artifact of a Node's Orbit footprint (ADR 0202). The digest comes from the
 * Gateway's own inputs and never reads the Node, so the catch-up can compare it without SSH.
 */
interface NodeFootprintArtifact
{
    /** A stable name, such as `caddy`. */
    public function name(): string;

    /**
     * Whether the Node carries this artifact. It can read stored state that `apply()` changes.
     *
     * @phpstan-impure
     */
    public function applies(Node $node): bool;

    /** The digest of what the Gateway renders for the Node now. */
    public function digest(Node $node): string;

    /**
     * Publishes the artifact on the Node. Each artifact compares with the live copy first and changes
     * nothing that already matches. Returns whether it changed the Node, or null when it cannot tell.
     */
    public function apply(Node $node): ?bool;
}
