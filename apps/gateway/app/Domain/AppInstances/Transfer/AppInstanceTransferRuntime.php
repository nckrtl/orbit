<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Transfer;

use App\Models\Instance;
use App\Models\Node;

interface AppInstanceTransferRuntime
{
    public function pause(Instance $instance): void;

    public function restore(Instance $instance): void;

    public function relocate(
        Instance $instance,
        Node $destination,
        string $sourcePath,
        string $workingDirectory,
    ): void;

    public function activate(Instance $instance): void;

    public function cleanupSourceArtifacts(Instance $instance, Node $sourceNode, string $sourcePath): void;
}
