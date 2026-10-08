<?php

declare(strict_types=1);

namespace App\Domain\Compute;

enum SandboxState: string
{
    case Reserved = 'reserved';
    case Creating = 'creating';
    case Running = 'running';
    case Stopping = 'stopping';
    case Stopped = 'stopped';
    case Starting = 'starting';
    case Destroying = 'destroying';
    case Destroyed = 'destroyed';
    case Uncertain = 'uncertain';
}
