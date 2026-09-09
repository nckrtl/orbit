<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Removal;

enum AppInstanceSourceRevalidationState: string
{
    case Present = 'present';
    case Quarantined = 'quarantined';
    case ReceiptPendingCleanup = 'receipt-pending-cleanup';
    case Completed = 'completed';
}
