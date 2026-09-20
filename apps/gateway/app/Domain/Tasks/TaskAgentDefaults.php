<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

final readonly class TaskAgentDefaults
{
    public const string ImplementerModel = 'codex-luna-lite';

    public const string ImplementerEffort = 'low';

    public const string ReviewerModel = 'claude-opus';

    public const string ReviewerEffort = 'high';
}
