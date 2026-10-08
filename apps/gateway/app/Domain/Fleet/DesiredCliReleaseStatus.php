<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

enum DesiredCliReleaseStatus: string
{
    case Available = 'available';

    /** The version is known, but CI has not published its release yet. It is published minutes after the checks pass. */
    case Pending = 'pending';

    case Unavailable = 'unavailable';
}
