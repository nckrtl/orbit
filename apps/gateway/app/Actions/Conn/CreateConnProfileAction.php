<?php

declare(strict_types=1);

namespace App\Actions\Conn;

use App\Data\Conn\CreateConnProfileData;
use App\Models\ConnProfile;

final readonly class CreateConnProfileAction
{
    public function execute(CreateConnProfileData $data): ConnProfile
    {
        return ConnProfile::query()->create([
            'name' => $data->name,
            'settings' => ['workspaces' => []],
            'settings_version' => 1,
        ]);
    }
}
