<?php

declare(strict_types=1);

namespace App\Domain\AppDefinitions;

enum DefinitionEnvironment: string
{
    case Development = 'development';
    case Production = 'production';
}
