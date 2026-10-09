<?php

declare(strict_types=1);

namespace App\Data\T3;

use Spatie\LaravelData\Data;

final class CreateT3ProfileData extends Data
{
    public function __construct(
        public string $name,
    ) {}
}
