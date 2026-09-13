<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;

final readonly class AppInstanceSourceProfileGuard
{
    public function assertRecorded(AppInstance $appInstance): void
    {
        if ($appInstance->source_is_laravel === null) {
            $this->refuse($appInstance);
        }
    }

    public function refuse(AppInstance $appInstance): never
    {
        throw new ResourceOperationException(
            errorCode: 'instance.source_profile_missing',
            message: "AppInstance [{$appInstance->name}] has no recorded source profile. "
                .'Repeat its creation request with recover_source_profile to record one.',
            status: 409,
        );
    }
}
