<?php

declare(strict_types=1);

namespace App\E2E;

use RuntimeException;

/**
 * The promoted manifest names topology snapshot resources that are not
 * on the host any more. The topology snapshot is not corrupt and nothing needs repairing
 * by hand: the manifest is simply behind the host, and one named command
 * rebuilds it.
 */
final class StaleTopologySnapshotManifest extends RuntimeException
{
    public const string RECOVERY_COMMAND = 'bin/e2e-topology-snapshot rebuild --main-sha=<sha>';
}
