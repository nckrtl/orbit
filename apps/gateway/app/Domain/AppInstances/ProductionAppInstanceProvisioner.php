<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Data\AppInstances\CreateAppInstanceData;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;

interface ProductionAppInstanceProvisioner
{
    /** @return array{appInstance: Instance, created: bool} */
    public function execute(CreateAppInstanceData $data, Project $app, Node $node, ?string $root): array;
}
