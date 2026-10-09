<?php

declare(strict_types=1);

namespace App\Data\T3;

use App\Models\T3Profile;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class T3ProfileData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public int $settingsVersion,
        public string $updatedAt,
    ) {}

    public static function fromModel(T3Profile $profile): self
    {
        return new self(
            id: $profile->id,
            name: $profile->name,
            settingsVersion: $profile->settings_version,
            updatedAt: $profile->updated_at->toIso8601String(),
        );
    }
}
