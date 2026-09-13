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
            message: 'The AppInstance has no recorded source profile. Repeat the same creation request with recover_source_profile to inspect the source and store the complete profile.',
            status: 409,
        );
    }
}
