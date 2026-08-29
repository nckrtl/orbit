<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

enum DoctorFamily: string
{
    case Node = 'node';
    case Role = 'role';
    case App = 'app';
    case Instance = 'instance';
    case Workspace = 'workspace';
    case Tool = 'tool';
    case Process = 'process';
    case Firewall = 'firewall';
}
