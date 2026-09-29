<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use SensitiveParameter;

interface InstanceEnvironmentWriter
{
    public function write(
        InstanceEnvironmentContext $context,
        #[SensitiveParameter]
        string $contents,
    ): InstanceEnvironmentWriteResult;
}
