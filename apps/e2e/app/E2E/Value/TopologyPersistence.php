<?php

declare(strict_types=1);

namespace App\E2E\Value;

enum TopologyPersistence: string
{
    case PersistentSnapshot = 'persistent-snapshot';
    case Disposable = 'disposable';
}
