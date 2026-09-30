<?php

declare(strict_types=1);

namespace App\Domain\Tools;

enum ToolInventoryPackageKind: string
{
    case Formula = 'formula';
    case Cask = 'cask';
    case Global = 'global';
}
