<?php

declare(strict_types=1);

namespace App\Services\SelfUpdate;

enum SelfUpdateOutcome: string
{
    case Updated = 'updated';
    case Unchanged = 'unchanged';

    /** The Gateway's CLI release is not published yet. Nothing changed; run self-update again later. */
    case Pending = 'pending';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
