<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Data\Fleet\DesiredCliReleaseData;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;

/**
 * Installs the Orbit CLI on a managed Linux Node when it is missing and configures its Gateway
 * connection with the Node's identity (ADR 0202). Provisioning, role converge, and the fleet rollout
 * use the same step.
 */
interface NodeCliInstaller
{
    /**
     * Installs the release's binary for the Node's architecture when the canonical path has no CLI
     * that can update itself, and writes the Node profile when it differs. A present CLI stays as it
     * is: `orbit self-update` replaces it. A checksum mismatch installs nothing.
     *
     * @throws ResourceOperationException
     */
    public function ensure(Node $node, DesiredCliReleaseData $release): NodeCliInstallation;

    /**
     * Reads the canonical path without changing it: `missing`, `present`, `stale`, or `foreign`.
     *
     * @throws ResourceOperationException
     */
    public function inspect(Node $node): string;
}
