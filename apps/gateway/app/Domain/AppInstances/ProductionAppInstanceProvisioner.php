<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Data\AppInstances\CreateAppInstanceData;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;

interface ProductionAppInstanceProvisioner
{
    /** @return array{appInstance: AppInstance, created: bool} */
    public function execute(CreateAppInstanceData $data, App $app, Node $node, ?string $root): array;
}
