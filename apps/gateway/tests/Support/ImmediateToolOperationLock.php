<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolOperationLock;
use Closure;

final class ImmediateToolOperationLock implements ToolOperationLock
{
    public int $runs = 0;

    /** @var list<array{nodeId: int, manager: ToolManagerName, package: string, operation: ToolOperation, versionConstraint: ?string}> */
    public array $arguments = [];

    /**
     * @template T
     *
     * @param Closure(): T $callback
     * @return T
     *
     * @mago-expect lint:excessive-parameter-list The fake records the complete typed lock identity.
     */
    public function run(
        int $nodeId,
        ToolManagerName $manager,
        string $package,
        ToolOperation $operation,
        ?string $versionConstraint,
        Closure $callback,
    ): mixed {
        $this->runs++;
        $this->arguments[] = [
            'nodeId' => $nodeId,
            'manager' => $manager,
            'package' => $package,
            'operation' => $operation,
            'versionConstraint' => $versionConstraint,
        ];

        return $callback();
    }
}
