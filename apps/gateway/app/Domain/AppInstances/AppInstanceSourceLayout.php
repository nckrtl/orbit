<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

enum AppInstanceSourceLayout: string
{
    case Checkout = 'checkout';
    case Worktree = 'worktree';
}
