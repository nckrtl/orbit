<?php

declare(strict_types=1);

namespace App\Actions\T3;

use App\Data\T3\ReplaceT3ProfileSettingsData;
use App\Domain\Shared\ResourceOperationException;
use App\Models\T3Profile;
use Illuminate\Support\Carbon;

/**
 * Replaces a profile's settings document when the client read the current version. Two devices that
 * edit from the same version cannot overwrite each other: the second one gets a conflict and rereads.
 */
final readonly class ReplaceT3ProfileSettingsAction
{
    public function execute(T3Profile $profile, ReplaceT3ProfileSettingsData $data): T3Profile
    {
        $updated = T3Profile::query()
            ->whereKey($profile->id)
            ->where('settings_version', $data->version)
            ->update([
                'settings' => json_encode($data->settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'settings_version' => $data->version + 1,
                'updated_at' => Carbon::now(),
            ]);

        $profile->refresh();

        if ($updated === 0) {
            throw new ResourceOperationException(
                't3.settings_version_conflict',
                "The profile settings changed since version {$data->version}. Read them again and retry.",
                409,
                details: ['current_version' => $profile->settings_version],
            );
        }

        return $profile;
    }
}
