<?php

declare(strict_types=1);

namespace App\Services\SelfUpdate;

enum RunningBinaryKind: string
{
    /** A standalone `orbit` binary: a static PHP with the CLI appended. Self-update replaces it. */
    case Binary = 'binary';

    /** A PHAR that a separate `php` runs. Self-update leaves it alone. */
    case Phar = 'phar';

    /** A source checkout. Self-update leaves it alone; `git pull` updates it. */
    case Source = 'source';
}
