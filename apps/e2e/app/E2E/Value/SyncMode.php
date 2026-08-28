<?php

declare(strict_types=1);

namespace App\E2E\Value;

enum SyncMode: string
{
    case Incremental = 'incremental';
    case Full = 'full';
}
