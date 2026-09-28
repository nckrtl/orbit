<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Data\AppInstances\CreateAppInstanceData;
use App\Domain\AppInstances\ProductionAppInstanceProvisioner;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;

final readonly class NativeProductionAppInstanceProvisioner implements ProductionAppInstanceProvisioner
{
    /** @return array{appInstance: Instance, created: bool} */
    public function execute(CreateAppInstanceData $data, Project $app, Node $node, ?string $root): array
    {
        throw new ResourceOperationException(
            errorCode: 'instance.candidate_required',
            message: 'New production AppInstances require a candidate. Use instance:clone.',
            status: 409,
        );
    }
}
