<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Shared\ResourceOperationException;
use RuntimeException;

final class NodeProvisioningLockException extends RuntimeException
{
    public function __construct(
        public readonly string $nodeName,
    ) {
        parent::__construct('Node provisioning is already active.');
    }

    public function toBusyException(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'node.provisioning_busy',
            message: "Node [{$this->nodeName}] is already changing.",
            status: 409,
        );
    }
}
