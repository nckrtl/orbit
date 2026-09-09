<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Environment;

use SensitiveParameter;

interface AppInstanceEnvironmentWriter
{
    public function write(
        AppInstanceEnvironmentContext $context,
        #[SensitiveParameter]
        string $contents,
    ): AppInstanceEnvironmentWriteResult;
}
