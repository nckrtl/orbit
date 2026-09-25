<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * Writes the one rubric reminder a thread receives per attempt.
 */
final readonly class TaskRubricReminder
{
    private const string ImplementerLead = 'Orbit could not confirm the brief is complete.';

    private const string ReviewerLead = 'Orbit could not confirm the review is complete.';

    /**
     * @param  list<TaskRubricItem>  $failures
     * @param  list<TaskDeliverable>  $deliverables
     * @param  string|null  $check  the Project task check command, or null when the Project has none
     */
    public static function compose(TaskThreadRole $role, array $failures, bool $final = false, array $deliverables = [], ?string $check = 'composer check'): string
    {
        $sentences = array_values(array_filter(
            array_map(static fn (TaskRubricItem $item): string => $item->reminder, $failures),
            static fn (string $sentence): bool => $sentence !== '',
        ));

        return $role === TaskThreadRole::Implementer
            ? implode(' ', [self::ImplementerLead, ...$sentences, TaskRunInstructions::implementer($deliverables, $check)])
            : implode(' ', [self::ReviewerLead, ...$sentences, TaskRunInstructions::reviewer($final, $deliverables)]);
    }
}
