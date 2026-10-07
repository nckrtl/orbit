<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

enum SandboxHostOperation: string
{
    case Provision = 'provision';
    case Observe = 'observe';
    case Capacity = 'capacity';
    case Park = 'park';
    case Resume = 'resume';
    case Destroy = 'destroy';
    case GuestCommand = 'guest_command';
}
