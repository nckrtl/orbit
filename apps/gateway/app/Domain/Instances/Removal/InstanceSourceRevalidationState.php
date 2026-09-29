<?php

declare(strict_types=1);

namespace App\Domain\Instances\Removal;

enum InstanceSourceRevalidationState: string
{
    case Present = 'present';
    case Quarantined = 'quarantined';
    case ReceiptPendingCleanup = 'receipt-pending-cleanup';
    case Completed = 'completed';
}
