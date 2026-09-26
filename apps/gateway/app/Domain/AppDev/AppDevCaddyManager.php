<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

use App\Domain\Nodes\RoleName;
use App\Models\Node;

interface AppDevCaddyManager
{
    public function converge(Node $node, RoleName $role = RoleName::AppDev): void;

    public function remove(Node $node, RoleName $role = RoleName::AppDev): void;
}
