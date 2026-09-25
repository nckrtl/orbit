<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * Renders the pull request description from the reviewer's fields, in a fixed order.
 */
final readonly class TaskPullRequestDescription
{
    /**
     * @param  string|null  $check  the Project task check command, or null when the Project has none (ADR 0125)
     */
    public static function render(TaskRunPullRequest $pullRequest, int $subtasks, ?string $check = 'composer check'): string
    {
        $lines = static fn (array $items): string => implode("\n", array_map(static fn (string $item): string => '- '.$item, $items));

        return implode("\n\n", [
            $pullRequest->summary,
            "## Changes\n\n".$lines($pullRequest->changes),
            "## Breaking changes\n\n".($pullRequest->breaking === [] ? 'None.' : $lines($pullRequest->breaking)),
            'Checks: each of the '.$subtasks.' subtasks '.($check === null ? '' : 'passed `'.$check.'` and ').'was approved by the reviewer.',
        ])."\n";
    }
}
