<?php

declare(strict_types=1);

namespace App\Domain\Instances\Transfer;

enum InstanceTransferStatus: string
{
    case Reserved = 'reserved';
    case InProgress = 'in_progress';
    case Failed = 'failed';
    case Completed = 'completed';
}
