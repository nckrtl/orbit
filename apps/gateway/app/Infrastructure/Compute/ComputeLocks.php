<?php

declare(strict_types=1);

namespace App\Infrastructure\Compute;

use App\Domain\Compute\ComputeException;
use Illuminate\Support\Facades\Cache;

final readonly class ComputeLocks
{
    /** @template T
     * @param  callable(): T  $operation
     * @return T
     */
    public function upcloud(callable $operation): mixed
    {
        $lock = Cache::lock('orbit:compute:upcloud', 180);
        if (! $lock->get()) {
            throw new ComputeException('compute.busy', 'Another UpCloud operation is running.');
        }
        try {
            return $operation();
        } finally {
            $lock->release();
        }
    }
}
