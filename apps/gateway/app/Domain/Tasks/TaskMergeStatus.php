<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/** The merge gate's last result for a review-and-merge task (ADR 0203). */
enum TaskMergeStatus: string
{
    /** A condition does not hold yet, such as a pending check. Orbit looks again on the next tick. */
    case Waiting = 'waiting';

    /** A condition failed and needs a change or a person, such as an unreviewed head or failed check. */
    case Refused = 'refused';

    /** The App merged the pull request. */
    case Merged = 'merged';
}
