<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Project;

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
    public static function compose(TaskThreadRole $role, array $failures, bool $final = false, array $deliverables = [], ?string $check = null, ?int $threadId = null, ?TaskTurnMode $mode = null): string
    {
        $sentences = array_values(array_filter(
            array_map(static fn (TaskRubricItem $item): string => $item->reminder, $failures),
            static fn (string $sentence): bool => $sentence !== '',
        ));
        $instructions = match (true) {
            $role === TaskThreadRole::Implementer => TaskTurnInstructions::implementer($deliverables, $check, $threadId),
            $mode?->consult === true => TaskTurnInstructions::consult($threadId),
            $mode?->relay === true => TaskTurnInstructions::relay($threadId),
            default => TaskTurnInstructions::reviewer($final, $deliverables, $threadId),
        };

        return implode(' ', [$role === TaskThreadRole::Implementer ? self::ImplementerLead : self::ReviewerLead, ...$sentences, $instructions]);
    }
}
