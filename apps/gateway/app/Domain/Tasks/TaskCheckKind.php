<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

enum TaskCheckKind: string
{
    /** The Project's setup steps and check on the fresh workspace, before the first implementer starts. */
    case Baseline = 'baseline';

    /** The check after an implementer's ready_for_review receipt. */
    case Handoff = 'handoff';
}
