<?php

declare(strict_types=1);

namespace App\Services\SelfUpdate;

enum SelfUpdateOutcome: string
{
    case Updated = 'updated';
    case Unchanged = 'unchanged';

    /** The Gateway's CLI release is not published yet. Nothing changed; run self-update again later. */
    case Pending = 'pending';

    /**
     * A command outcome only: a step that should have run could not, such as an unavailable release or a missing
     * root. Nothing is known to be wrong with this machine, but it is not up to date either.
     */
    case Incomplete = 'incomplete';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
