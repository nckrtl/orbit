<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use RuntimeException;

final class NodeProvisioningLockException extends RuntimeException
{
    public function __construct(
        public readonly string $nodeName,
    ) {
        parent::__construct('Node provisioning is already active.');
    }
}
