<?php

declare(strict_types=1);

namespace App\Data\Clusters;

use Spatie\LaravelData\Data;

final class CreateClusterData extends Data
{
    public function __construct(
        public string $name,
        public ?string $tld,
    ) {}
}
