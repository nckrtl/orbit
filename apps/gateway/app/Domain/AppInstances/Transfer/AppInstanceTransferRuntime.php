<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Transfer;

use App\Models\AppInstance;
use App\Models\Node;

interface AppInstanceTransferRuntime
{
    public function pause(AppInstance $instance): void;

    public function restore(AppInstance $instance): void;

    public function relocate(
        AppInstance $instance,
        Node $destination,
        string $sourcePath,
        string $workingDirectory,
    ): void;

    public function activate(AppInstance $instance): void;

    public function cleanupSourceArtifacts(AppInstance $instance, Node $sourceNode, string $sourcePath): void;
}
