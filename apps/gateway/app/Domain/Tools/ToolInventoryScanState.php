<?php

declare(strict_types=1);

namespace App\Domain\Tools;

/**
 * The five scan states from the tool inventory contract.
 * Packages are an inventory only when the state is complete.
 */
enum ToolInventoryScanState: string
{
    case Complete = 'complete';
    case Absent = 'absent';
    case Unsupported = 'unsupported';
    case Incomplete = 'incomplete';
    case Conflicting = 'conflicting';
}
