<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

final readonly class ManagedUserAccount
{
    public function __construct(
        public string $user,
        public string $group,
        public string $home,
    ) {}
}
