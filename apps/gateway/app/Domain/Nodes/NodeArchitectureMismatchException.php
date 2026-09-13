<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Shared\ResourceOperationException;
use RuntimeException;

final class NodeArchitectureMismatchException extends RuntimeException
{
    public const string ERROR_CODE = 'node.architecture_mismatch';

    public function __construct(
        public readonly string $nodeName,
        public readonly string $requested,
        public readonly string $observed,
    ) {
        parent::__construct(
            "Node [{$this->nodeName}] reports architecture [{$this->observed}], not [{$this->requested}].",
        );
    }

    public function toRefusal(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: self::ERROR_CODE,
            message: $this->getMessage(),
            status: 409,
            previous: $this,
        );
    }
}
