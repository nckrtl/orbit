<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Transfer;

use App\Domain\Nodes\Storage\StoragePath;
use App\Models\AppInstance;
use App\Models\AppInstanceTransfer;
use App\Models\Node;

interface AppInstanceTransferSource
{
    public function capture(AppInstance $instance): TransferSourceCapture;

    public function materialize(
        TransferSourceCapture $capture,
        Node $destination,
        StoragePath $path,
    ): TransferCheckout;

    public function discardDestination(Node $node, StoragePath $path): void;

    public function cleanupSource(AppInstanceTransfer $transfer): TransferCleanupResult;
}
