<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

final class TaskDefinitionName
{
    public const string Pattern = '[a-z0-9]+(?:-[a-z0-9]+)*';

    public const int MaxLength = 63;
}
