<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Environment;

interface AppInstanceOperationPreflight
{
    public function assertEnvironmentReadable(AppInstanceEnvironmentContext $context): void;
}
