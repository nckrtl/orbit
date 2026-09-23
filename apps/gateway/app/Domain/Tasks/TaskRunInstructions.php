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

    public static function reviewer(): string
    {
        return 'Do not commit; Orbit commits after you approve. When the subtask meets its brief, end your turn with .git/orbit/run --outcome=approved --summary="What you checked". Otherwise end your turn with .git/orbit/run --outcome=changes_requested --summary="The findings the implementer must address". If something outside the review stops you, end your turn with .git/orbit/run --outcome=blocked --summary="What stops you".';
    }
}
