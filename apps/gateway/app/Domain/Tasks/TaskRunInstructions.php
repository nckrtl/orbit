<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * Tells an agent how to end its turn with the run script.
 */
final readonly class TaskRunInstructions
{
    public static function implementer(): string
    {
        return 'When the brief is complete and composer check passes, end your turn with .git/orbit/run --outcome=ready_for_review --summary="What you changed". If something outside the brief stops you, end your turn with .git/orbit/run --outcome=blocked --summary="What stops you".';
    }

    /**
     * The approval of the last subtask also describes the pull request Orbit opens.
     */
    public static function reviewer(bool $final = false): string
    {
        $approve = $final
            ? 'This is the last subtask. When it meets its brief, end your turn with .git/orbit/run --outcome=approved --summary="What you checked" --pr-summary="One or two sentences about the whole feature" --pr-change="A new feature or behavior change" --pr-breaking="A breaking change". Repeat --pr-change for each change in the feature, and --pr-breaking for each breaking change, or pass --pr-breaking=none.'
            : 'When the subtask meets its brief, end your turn with .git/orbit/run --outcome=approved --summary="What you checked".';

        return 'Do not commit; Orbit commits after you approve. '.$approve.' Otherwise end your turn with .git/orbit/run --outcome=changes_requested --summary="The findings the implementer must address". If something outside the review stops you, end your turn with .git/orbit/run --outcome=blocked --summary="What stops you".';
    }
}
