<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Domain\Shared\ResourceOperationException;

final readonly class AppInstanceSourceProfileGuard
{
    public function refuseMissing(): never
    {
        throw new ResourceOperationException(
            errorCode: 'instance.source_profile_missing',
            message: 'The Instance has no recorded source profile and cannot be used.',
            status: 409,
        );
    }
}
