<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

enum ExporterPreference: string
{
    case Enabled = 'enabled';
    case Disabled = 'disabled';
}
