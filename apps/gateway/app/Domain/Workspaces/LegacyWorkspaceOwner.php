<?php

declare(strict_types=1);

namespace App\Domain\Workspaces;

use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;

final readonly class LegacyWorkspaceOwner
{
    public function refuseAppInstance(int $instanceId): void
    {
        if (! AppInstance::query()->whereKey($instanceId)->exists()) {
            return;
        }

        throw new ResourceOperationException(
            errorCode: 'workspace.unsupported_for_app_instance',
            message: "Workspaces are a legacy Instance surface and cannot be created for AppInstance [{$instanceId}].",
        );
    }
}
