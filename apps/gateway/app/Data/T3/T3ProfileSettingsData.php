<?php

declare(strict_types=1);

namespace App\Data\T3;

use App\Models\T3Profile;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** The profile's settings document as T3 Code wrote it; its own keys keep T3's camelCase. */
#[MapOutputName(SnakeCaseMapper::class)]
final class T3ProfileSettingsData extends Data
{
    /** @param array<string, mixed> $settings */
    public function __construct(
        public int $profileId,
        public int $version,
        public array $settings,
        public string $updatedAt,
    ) {}

    public static function fromModel(T3Profile $profile): self
    {
        return new self(
            profileId: $profile->id,
            version: $profile->settings_version,
            settings: $profile->settings,
            updatedAt: $profile->updated_at->toIso8601String(),
        );
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function toArray(): array
    {
        return [
            'profile_id' => $this->profileId,
            'version' => $this->version,
            'settings' => $this->settings,
            'updated_at' => $this->updatedAt,
        ];
    }
}
