<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Models\Instance;

final readonly class ShowAppInstanceAction
{
    public function handle(Instance $appInstance): Instance
    {
        return $appInstance;
    }
}
