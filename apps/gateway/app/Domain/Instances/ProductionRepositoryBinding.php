<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\Instance;

/**
 * Reads and rewrites the repository URLs that a production home records.
 */
interface ProductionRepositoryBinding
{
    public function inspect(Instance $instance): ProductionRepositoryRecord;

    /**
     * Moves every recorded URL from its value in `$expected` to its value in `$target`.
     * A value that already matches `$target` stays. Any other value stops the rewrite.
     */
    public function rebind(Instance $instance, ProductionRepositoryRecord $expected, ProductionRepositoryRecord $target): void;
}
