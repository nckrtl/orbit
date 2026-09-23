<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * Writes the one rubric reminder a thread receives per attempt and recognizes it in a transcript.
 */
final readonly class TaskRubricReminder
{
    private const string ImplementerLead = 'Orbit could not confirm the brief is complete.';

    private const string ImplementerClosing = 'If it is, reply with a short summary of what changed and the composer check result. If something outside the brief stops you, say what it is.';

    private const string ReviewerLead = 'Orbit could not confirm the review is complete.';

    private const string ReviewerClosing = 'If something outside the review stops you, say what it is.';

    /** @param list<TaskRubricItem> $failures */
    public static function compose(TaskThreadRole $role, array $failures): string
    {
        $sentences = array_values(array_filter(
            array_map(static fn (TaskRubricItem $item): string => $item->reminder, $failures),
            static fn (string $sentence): bool => $sentence !== '',
        ));

        return $role === TaskThreadRole::Implementer
            ? implode(' ', [self::ImplementerLead, ...$sentences, self::ImplementerClosing])
            : implode(' ', [self::ReviewerLead, ...$sentences, self::ReviewerClosing]);
    }

    public static function isReminder(string $text): bool
    {
        $text = ltrim($text);

        return str_starts_with($text, self::ImplementerLead) || str_starts_with($text, self::ReviewerLead);
    }
}
