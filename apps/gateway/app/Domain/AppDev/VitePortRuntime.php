<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;

interface VitePortRuntime
{
    /** @param list<int> $excluded */
    public function selectPort(Node $node, int $preferred, array $excluded): int;

    public function ownsListener(Process $process, AppInstance $instance, int $port): bool;

    public function ready(Process $process, AppInstance $instance, int $port): bool;

    public function suspendTraffic(AppInstance $instance): void;

    public function prepare(Process $process, AppInstance $instance): void;

    public function project(AppInstance $instance): void;
}
