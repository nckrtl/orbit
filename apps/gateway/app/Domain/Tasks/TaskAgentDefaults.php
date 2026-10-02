<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

final readonly class TaskAgentDefaults
{
    public const string ImplementerModel = 'gpt-5.6-luna';

    public const string ReviewerModel = 'gpt-5.6-luna';
}
