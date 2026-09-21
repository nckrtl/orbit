<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

final readonly class TaskAgentDefaults
{
    public const string ImplementerInstanceId = 'codex';

    public const string ImplementerModel = 'gpt-5.6-luna';

    public const string ImplementerEffortKey = 'reasoningEffort';

    public const string ImplementerEffort = 'low';

    public const string ReviewerInstanceId = 'claudeAgent';

    public const string ReviewerModel = 'claude-opus-5';

    public const string ReviewerEffortKey = 'effort';

    public const string ReviewerEffort = 'high';
}
