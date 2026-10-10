<?php

declare(strict_types=1);

namespace App\Data\Conn;

use Spatie\LaravelData\Data;

final class ReplaceConnProfileSettingsData extends Data
{
    /** @param array<string, mixed> $settings */
    public function __construct(
        public int $version,
        public array $settings,
    ) {}
}
