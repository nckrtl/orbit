<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use Closure;

interface ToolOperationLock
{
    /**
     * @template T
     *
     * @param Closure(): T $callback
     * @return T
     *
     * @mago-expect lint:excessive-parameter-list The lock identity requires the complete typed tool operation.
     */
    public function run(
        int $nodeId,
        ToolManagerName $manager,
        string $package,
        ToolOperation $operation,
        ?string $versionConstraint,
        Closure $callback,
    ): mixed;
}
