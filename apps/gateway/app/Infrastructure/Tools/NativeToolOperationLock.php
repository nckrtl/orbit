<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerScopeLock;
use App\Domain\Tools\ToolManagerScopeLockException;
use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolOperationException;
use App\Domain\Tools\ToolOperationLock;
use App\Domain\Tools\ToolOutcome;
use Closure;
use Illuminate\Support\Facades\Cache;

final readonly class NativeToolOperationLock implements ToolOperationLock
{
    public function __construct(
        private ToolManagerScopeLock $managerScope,
    ) {}

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
    ): mixed {
        $identity = Cache::lock(
            "orbit:tool:{$nodeId}:{$manager->value}:".hash('sha256', $package),
            3_600,
        );

        if (! $identity->get()) {
            throw $this->lockedException(
                nodeId: $nodeId,
                manager: $manager,
                package: $package,
                operation: $operation,
                versionConstraint: $versionConstraint,
                message: 'A tool mutation for this package is already active.',
            );
        }

        try {
            try {
                return $this->managerScope->run($nodeId, $manager, $callback);
            } catch (ToolManagerScopeLockException) {
                throw $this->lockedException(
                    nodeId: $nodeId,
                    manager: $manager,
                    package: $package,
                    operation: $operation,
                    versionConstraint: $versionConstraint,
                    message: 'A shared tool manager mutation is already active.',
                );
            }
        } finally {
            $identity->release();
        }
    }

    /** @mago-expect lint:excessive-parameter-list The failure preserves the complete typed lock identity. */
    private function lockedException(
        int $nodeId,
        ToolManagerName $manager,
        string $package,
        ToolOperation $operation,
        ?string $versionConstraint,
        string $message,
    ): ToolOperationException {
        return new ToolOperationException(
            step: $operation->value,
            errorCode: 'tool.operation_locked',
            outcome: ToolOutcome::ManagerFailed,
            status: 409,
            nodeId: $nodeId,
            manager: $manager->value,
            package: $package,
            versionConstraint: $versionConstraint,
            message: $message,
        );
    }
}
