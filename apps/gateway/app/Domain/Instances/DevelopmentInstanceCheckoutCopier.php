<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\Instance;

interface DevelopmentInstanceCheckoutCopier
{
    /**
     * Read the source checkout. This must not create, change, or delete anything on the Node.
     */
    public function inspect(Instance $source, string $branch): DevelopmentInstanceCopyInspection;

    /**
     * Copy the source checkout onto the target path, then point the target branch at the source HEAD.
     * A failure after the copy starts removes the owned partial tree.
     * `details.copy_started` is `1` when that removal was required.
     */
    public function copy(
        Instance $source,
        Instance $target,
        string $branch,
        string $expectedHead,
        string $occupiedCode,
    ): DevelopmentInstanceCopyResult;

    public function deleteMarker(Instance $target): void;

    public function discardPartial(Instance $target): void;
}
