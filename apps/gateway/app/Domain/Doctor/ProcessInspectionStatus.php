<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

enum ProcessInspectionStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Running = 'running';
    case Created = 'created';
    case Exited = 'exited';
    case Other = 'other';
}
