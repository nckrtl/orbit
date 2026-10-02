<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

enum TaskReviewReadStatus: string
{
    case Complete = 'complete';
    case Disabled = 'disabled';
    case InvalidTrust = 'invalid_trust';
    case Unreadable = 'unreadable';
    case Overflow = 'overflow';
    case Changed = 'changed';
}
