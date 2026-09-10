<?php

declare(strict_types=1);

namespace App\E2E\Value;

enum ScenarioStatus: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case Blocked = 'blocked';
    case InfrastructureError = 'infrastructure-error';
}
