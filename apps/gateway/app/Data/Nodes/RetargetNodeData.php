<?php

declare(strict_types=1);

namespace App\Data\Nodes;

use Spatie\LaravelData\Data;

final class RetargetNodeData extends Data
{
    public function __construct(
        public string $name,
        public string $publicSshHost,
        public int $publicSshPort = 22,
    ) {}
}
