<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Environment;

interface AppInstanceEnvironmentReader
{
    public function read(AppInstanceEnvironmentContext $context): string;
}
