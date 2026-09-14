<?php

declare(strict_types=1);

namespace App\Domain\Herdr;

enum HerdrSessionManagement: string
{
    case Managed = 'managed';
    case External = 'external';
}
