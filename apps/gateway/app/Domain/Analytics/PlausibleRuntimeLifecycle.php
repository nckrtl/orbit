<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Models\Node;
use App\Models\Process;
use SensitiveParameter;

/** Keeps the one `plausible` Process of the analytics role's node at the intended specification. */
interface PlausibleRuntimeLifecycle
{
    public function converge(
        Node $node,
        string $version,
        #[SensitiveParameter]
        AnalyticsStorageConnection $storage,
        #[SensitiveParameter]
        string $secretKeyBase,
    ): Process;

    public function remove(Node $node): void;

    /** Deletes the Process record of a node Orbit cannot reach; the container stays on the box. */
    public function forget(Node $node): void;
}
