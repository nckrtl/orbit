<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Storage;

enum CheckoutPathOrigin: string
{
    case Derived = 'derived';
    case Explicit = 'explicit';
}
