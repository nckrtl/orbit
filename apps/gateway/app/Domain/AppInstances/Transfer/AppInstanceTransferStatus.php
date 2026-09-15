<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Transfer;

enum AppInstanceTransferStatus: string
{
    case Reserved = 'reserved';
    case InProgress = 'in_progress';
    case Failed = 'failed';
    case Completed = 'completed';
}
