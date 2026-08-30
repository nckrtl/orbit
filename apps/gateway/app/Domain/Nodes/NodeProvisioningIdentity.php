<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

final readonly class NodeProvisioningIdentity
{
    public function __construct(
        public string $bootstrapUser,
        public string $managedUser,
    ) {}
}
