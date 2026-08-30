<?php

declare(strict_types=1);

namespace App\E2E;

use RuntimeException;

/**
 * The promoted manifest of this checkout names standby resources that are not
 * on the host any more. The standby is not corrupt and nothing needs repairing
 * by hand: the manifest is simply behind the host, and one named command
 * rebuilds it.
 */
final class StaleStandbyManifest extends RuntimeException
{
    public const string RECOVERY_COMMAND = 'bin/e2e-standby rebuild --main-sha=<sha>';
}
