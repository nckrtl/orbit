<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Data\Instances\CreateInstanceData;
use App\Domain\Instances\ProductionInstanceProvisioner;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;

final readonly class NativeProductionInstanceProvisioner implements ProductionInstanceProvisioner
{
    /**
     * @param  array<string, array{path: string, web_root: ?string}>  $appOverrides
     * @return array{instance: Instance, created: bool}
     */
    public function execute(CreateInstanceData $data, Project $project, Node $node, array $appOverrides): array
    {
        throw new ResourceOperationException(
            errorCode: 'instance.candidate_required',
            message: 'New production Instances require a candidate. Use instance:clone.',
            status: 409,
        );
    }
}
