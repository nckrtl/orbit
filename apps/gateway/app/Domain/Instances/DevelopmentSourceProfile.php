<?php

declare(strict_types=1);

namespace App\Domain\Instances;

final readonly class DevelopmentSourceProfile
{
    public function __construct(
        public ?string $phpVersion,
        public bool $laravel,
    ) {}
}
