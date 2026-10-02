<?php

declare(strict_types=1);

namespace App\Domain\Instances\DatabaseClone;

/**
 * The last finished step of an Instance's database copy, kept on its connection. A retry of
 * `instance:create` continues after it. Data counts as copied only after the copy finished.
 */
enum DatabaseCloneStep: string
{
    case Recorded = 'recorded';
    case Filled = 'filled';
    case Complete = 'complete';
}
