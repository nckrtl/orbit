<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

enum TaskJevOutcome: string
{
    case CompletedSuccessfully = 'completed_successfully';
    case ChangesRequested = 'changes_requested';
    case AssistanceRequired = 'assistance_required';

    /** @return array<string, string> */
    public static function choiceCriteria(): array
    {
        return [
            self::CompletedSuccessfully->value => 'The assigned work is complete and the recent thread evidence contains a passing composer check.',
            self::ChangesRequested->value => 'The reviewer found actionable changes and is the reviewer thread.',
            self::AssistanceRequired->value => 'Evidence is missing, checks failed, the agent is blocked, or the facts are ambiguous.',
        ];
    }
}
