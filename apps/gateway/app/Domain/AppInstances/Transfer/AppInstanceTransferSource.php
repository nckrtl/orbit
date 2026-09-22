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

    public function prepareSource(TransferSourceAttempt $attempt): TransferSourceAttempt;

    public function capture(AppInstance $instance, TransferArchiveAttempt $attempt, TransferSourceAttempt $sourceAttempt): TransferSourceCapture;

    public function prepareDestination(TransferDestinationAttempt $attempt): TransferDestinationAttempt;

    public function materialize(
        TransferSourceCapture $capture,
        Node $destination,
        StoragePath $path,
        TransferArchiveAttempt $attempt,
        TransferDestinationAttempt $destinationAttempt,
    ): TransferCheckout;

    /** @return list<'source'|'destination'> */
    public function cleanupArchives(TransferArchiveAttempt $attempt): array;

    public function discardDestination(TransferDestinationAttempt $attempt): void;

    public function cleanupSource(AppInstanceTransfer $transfer): TransferCleanupResult;
}
