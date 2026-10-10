<?php

declare(strict_types=1);

namespace App\Data\Conn;

use App\Models\ConnProfile;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** The profile's settings document as Conn wrote it; its own keys keep Conn's camelCase. */
#[MapOutputName(SnakeCaseMapper::class)]
final class ConnProfileSettingsData extends Data
{
    /** @param array<string, mixed> $settings */
    public function __construct(
        public int $profileId,
        public int $version,
        public array $settings,
        public string $updatedAt,
    ) {}

    public static function fromModel(ConnProfile $profile): self
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
