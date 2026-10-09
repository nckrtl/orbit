<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use SensitiveParameter;

/**
 * Seeds or merges `.env.testing` next to `.env` with the same checks, mode, and atomic rename. It never writes a
 * file that Git tracks in the checkout.
 */
interface InstanceTestEnvironmentWriter
{
    public const string FILE = '.env.testing';

    /**
     * Write `$contents` as a new file when none exists. In an existing file, set only the `$managedKeys` lines of
     * `$contents`, remove the other `$managedKeys`, and keep every other line. Returns a tracked result, and changes
     * nothing, when Git tracks the file.
     *
     * @param  list<string>  $managedKeys
     */
    public function mergeTesting(
        InstanceEnvironmentContext $context,
        #[SensitiveParameter]
        string $contents,
        array $managedKeys,
    ): InstanceEnvironmentWriteResult;
}
