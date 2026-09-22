<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Transfer;

use App\Domain\Nodes\Storage\StoragePath;
use App\Models\AppInstance;
use App\Models\AppInstanceTransfer;
use App\Models\Node;

interface AppInstanceTransferSource
{
    public function prepareArchives(TransferArchiveAttempt $attempt): TransferArchiveAttempt;

    public function capture(AppInstance $instance, TransferArchiveAttempt $attempt): TransferSourceCapture;

    public function materialize(
        TransferSourceCapture $capture,
        Node $destination,
        StoragePath $path,
        TransferArchiveAttempt $attempt,
    ): TransferCheckout;

    /** @return list<'source'|'destination'> */
    public function cleanupArchives(TransferArchiveAttempt $attempt): array;

    public function discardDestination(Node $node, StoragePath $path): void;

    public function cleanupSource(AppInstanceTransfer $transfer): TransferCleanupResult;
}
