<?php

declare(strict_types=1);

namespace App\Data\Instances;

use Spatie\LaravelData\Data;

final class InstanceSourceIdentityData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}
}
