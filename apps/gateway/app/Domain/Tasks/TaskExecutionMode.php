<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

enum TaskExecutionMode: string
{
    case Managed = 'managed';
    case ExistingThread = 'existing_thread';
}
