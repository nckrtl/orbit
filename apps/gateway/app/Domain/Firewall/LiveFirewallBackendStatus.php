<?php

declare(strict_types=1);

namespace App\Domain\Firewall;

enum LiveFirewallBackendStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Absent = 'absent';
    case Unreachable = 'unreachable';
}
