<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstanceDestinationGuard;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;

final readonly class RemoteAppInstanceDestinationGuard implements AppInstanceDestinationGuard
{
    public function __construct(
        private AppDevSshExecutor $ssh,
    ) {}

    public function assertUnoccupied(Node $node, StoragePath $destination): void
    {
        try {
            $this->ssh->execute(
                $node,
                new RemoteCommand(
                    arguments: ['bash', '-seu', '--', $destination->value],
                    input: <<<'BASH'
                        destination=$1
                        test ! -e "$destination"
                        test ! -L "$destination"
                        BASH,
                ),
                step: 'app-instance-destination-occupied',
                errorCode: 'instance.migration_conflict',
            );
        } catch (RuntimeConvergenceException $exception) {
            throw new ResourceOperationException(
                errorCode: 'instance.migration_conflict',
                message: "AppInstance destination [{$destination->value}] is occupied by unmanaged data.",
                status: 409,
                previous: $exception,
            );
        }
    }
}
