<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Shared\ResourceOperationException;
use App\Models\InstanceAppProjection;
use Closure;

/** Admission for unrelated mutations, using the existing Instance operation lock hierarchy. */
final readonly class AdmitInstanceAppMutationAction
{
    public function __construct(private InstanceEnvironmentOperationLock $operations) {}

    /**
     * @template T
     *
     * @param  list<int>  $instanceIds
     * @param  Closure(): T  $operation
     * @return T
     */
    public function execute(array $instanceIds, Closure $operation, ?string $busyCode = null): mixed
    {
        try {
            return $this->operations->run($instanceIds, function () use ($instanceIds, $operation): mixed {
                InstanceAppProjection::assertAvailable($instanceIds);

                return $operation();
            });
        } catch (ResourceOperationException $exception) {
            if ($exception->errorCode === 'env.operation_busy' && $busyCode !== null) {
                throw new ResourceOperationException($busyCode, 'Another Instance operation is active. Retry the request.', 409, previous: $exception);
            }
            throw $exception;
        }
    }
}
