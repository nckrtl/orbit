<?php

declare(strict_types=1);

namespace App\Data\AppInstances;

final readonly class PrepareAppInstanceDeploymentLayoutData
{
    public function __construct(public ?string $sqliteSourcePath) {}
}
