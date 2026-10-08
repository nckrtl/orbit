<?php

declare(strict_types=1);

namespace App\Actions\T3;

use App\Data\T3\CreateT3ProfileData;
use App\Models\T3Profile;

final readonly class CreateT3ProfileAction
{
    public function execute(CreateT3ProfileData $data): T3Profile
    {
        return T3Profile::query()->create([
            'name' => $data->name,
            'settings' => ['workspaces' => []],
            'settings_version' => 1,
        ]);
    }
}
