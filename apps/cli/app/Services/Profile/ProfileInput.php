<?php

declare(strict_types=1);

namespace App\Services\Profile;

final readonly class ProfileInput
{
    public function __construct(
        public string $url,
        public string $authMode,
        public ?string $user,
    ) {}
}
