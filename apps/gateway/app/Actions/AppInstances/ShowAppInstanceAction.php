<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Models\AppInstance;

final readonly class ShowAppInstanceAction
{
    public function handle(AppInstance $appInstance): AppInstance
    {
        return $appInstance;
    }
}
