<?php

declare(strict_types=1);

namespace App\Data\Conn;

use SensitiveParameter;
use Spatie\LaravelData\Data;

final class RegisterConnEnvironmentData extends Data
{
    public function __construct(
        public string $environmentId,
        public string $label,
        public string $url,
        #[SensitiveParameter] public string $adminSession,
    ) {}
}
