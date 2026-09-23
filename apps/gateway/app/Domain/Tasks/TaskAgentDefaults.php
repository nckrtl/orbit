<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

final readonly class TaskAgentDefaults
{
    public const string ImplementerModel = 'gpt-5.6-luna';

    public const string ImplementerEffort = 'high';

    public const string ReviewerModel = 'claude-opus-5';

    public const string ReviewerEffort = 'high';
}
