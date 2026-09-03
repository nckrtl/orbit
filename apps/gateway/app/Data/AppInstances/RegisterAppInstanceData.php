<?php

declare(strict_types=1);

namespace App\Data\AppInstances;

final readonly class RegisterAppInstanceData
{
    public function __construct(
        public string $appSlug,
        public string $checkoutPath,
        public ?string $name,
        public ?string $root,
    ) {}
}
