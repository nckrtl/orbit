<?php

declare(strict_types=1);

namespace App\Data\T3;

use Spatie\LaravelData\Data;

final class ReplaceT3ProfileSettingsData extends Data
{
    /** @param array<string, mixed> $settings */
    public function __construct(
        public int $version,
        public array $settings,
    ) {}
}
