<?php

declare(strict_types=1);

namespace App\Domain\Problems;

enum ProblemSource: string
{
    case Doctor = 'doctor';
    case Activity = 'activity';
    case Log = 'log';
    case Assist = 'assist';
    case Release = 'release';
}
