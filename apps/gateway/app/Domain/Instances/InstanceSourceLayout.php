<?php

declare(strict_types=1);

namespace App\Domain\Instances;

enum InstanceSourceLayout: string
{
    case Checkout = 'checkout';
    case Worktree = 'worktree';
}
