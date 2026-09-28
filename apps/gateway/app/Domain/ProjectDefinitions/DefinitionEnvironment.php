<?php

declare(strict_types=1);

namespace App\Domain\ProjectDefinitions;

enum DefinitionEnvironment: string
{
    case Production = 'production';
}
