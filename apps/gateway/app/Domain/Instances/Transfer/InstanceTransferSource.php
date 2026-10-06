<?php

declare(strict_types=1);

namespace App\Domain\Instances\Transfer;

use App\Domain\Nodes\Storage\StoragePath;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Node;

interface InstanceTransferSource
{
    public function capture(Instance $instance, ?string $sqliteSourcePath = null): TransferSourceCapture;

    public function materialize(
        TransferSourceCapture $capture,
        Node $destination,
        StoragePath $path,
    ): TransferCheckout;

    public function discardDestination(Node $node, StoragePath $path): void;

    public function verifyDestination(InstanceTransfer $transfer): void;

    public function cleanupSource(InstanceTransfer $transfer): TransferCleanupResult;
}
