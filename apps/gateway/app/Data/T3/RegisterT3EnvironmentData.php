<?php

declare(strict_types=1);

namespace App\Data\T3;

use SensitiveParameter;
use Spatie\LaravelData\Data;

final class RegisterT3EnvironmentData extends Data
{
    public function __construct(
        public string $environmentId,
        public string $label,
        public string $url,
        #[SensitiveParameter] public string $adminSession,
    ) {}
}
