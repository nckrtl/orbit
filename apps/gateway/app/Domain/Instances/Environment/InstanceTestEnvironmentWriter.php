<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use SensitiveParameter;

/** Replaces `.env.testing` next to `.env` with the same checks, mode, and atomic rename. */
interface InstanceTestEnvironmentWriter
{
    public const string FILE = '.env.testing';

    public function writeTesting(
        InstanceEnvironmentContext $context,
        #[SensitiveParameter]
        string $contents,
    ): InstanceEnvironmentWriteResult;
}
