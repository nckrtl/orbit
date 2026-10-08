<?php

declare(strict_types=1);

namespace App\Domain\Compute;

enum SandboxPower: string
{
    case Running = 'running';
    case Stopped = 'stopped';
    case Destroyed = 'destroyed';
}
