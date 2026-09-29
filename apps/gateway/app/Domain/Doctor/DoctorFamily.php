<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Models\Instance;

enum DoctorFamily: string
{
    case Node = 'node';
    case Role = 'role';
    case Project = 'project';
    case Instance = 'instance';
    case Schedule = 'schedule';
    case Tool = 'tool';
    case Process = 'process';
    case Firewall = 'firewall';
    case DatabaseConnection = 'database_connection';
    case Route = 'route';
}
