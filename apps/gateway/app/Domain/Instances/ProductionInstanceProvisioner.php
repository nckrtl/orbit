<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Data\Instances\CreateInstanceData;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;

interface ProductionInstanceProvisioner
{
    /**
     * @param  array<string, array{path: string, web_root: ?string}>  $appOverrides
     * @return array{instance: Instance, created: bool}
     */
    public function execute(CreateInstanceData $data, Project $project, Node $node, array $appOverrides): array;
}
