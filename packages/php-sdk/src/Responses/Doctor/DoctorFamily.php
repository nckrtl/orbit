<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Doctor;

enum DoctorFamily: string
{
    case Node = 'node';
    case Role = 'role';
    case App = 'app';
    case Instance = 'instance';
    case Workspace = 'workspace';
    case Schedule = 'schedule';
    case Tool = 'tool';
    case Process = 'process';
    case Firewall = 'firewall';
}
