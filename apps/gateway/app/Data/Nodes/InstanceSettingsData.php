<?php

declare(strict_types=1);

namespace App\Data\Nodes;

use Spatie\LaravelData\Data;

final class InstanceSettingsData extends Data
{
    public function __construct(
        public ?string $path = null,
    ) {}
}
