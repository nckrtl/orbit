<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

interface InstanceEnvironmentReader
{
    public function read(InstanceEnvironmentContext $context): string;
}
