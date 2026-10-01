<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * One fixup subtask for a conflict or a failed check on a settling pull request (ADR 0164).
 */
final readonly class TaskSettlingFixup
{
    /** A problem keeps at most this many fixups, in any status. */
    public const int Limit = 2;

    /** A group keeps at most this many Gateway fixups in total, in any status. The next problem asks for assistance. */
    public const int GroupLimit = 3;

    /**
     * @param  list<array<string, string>>  $deliverables
     */
    public function __construct(
        public string $identity,
        public string $title,
        public string $brief,
        public array $deliverables,
    ) {}

    /** The base ref of a conflict fixup, or null when this fixup repairs a check. */
    public function conflictBase(): ?string
    {
        if (! str_starts_with($this->identity, 'conflict:')) {
            return null;
        }

        return substr($this->identity, strlen('conflict:'));
    }

    /**
     * Problems in the order one tick considers them: the conflict, then failed checks in GitHub's order.
     * The Project slug and CI job names do not change that order. Each plan snapshots `$taskCheck`.
     *
     * @param  list<TaskPullRequestCheck>  $failedChecks
     * @return list<self>
     */
    public static function plans(?string $taskCheck, bool $conflicts, ?string $baseRef, array $failedChecks): array
    {
        $deliverables = self::deliverables($taskCheck);
        $plans = [];
        if ($conflicts) {
            $base = $baseRef ?? 'the base branch';
            $plans[] = new self(
                identity: 'conflict:'.$base,
                title: 'Merge origin/'.$base,
                brief: 'Merge origin/'.$base.' into the task branch and resolve the conflicts. Do not rebase and do not force-push.',
                deliverables: $deliverables,
            );
        }

        foreach ($failedChecks as $check) {
            $plans[] = new self(
                identity: 'check:'.$check->name,
                title: mb_substr('Fix '.$check->name, 0, 160),
                brief: 'Check '.$check->name.' failed'.($check->url !== null ? ': '.$check->url : '').'. Do not rebase and do not force-push.',
                deliverables: $deliverables,
            );
        }

        return $plans;
    }

    /**
     * The Project task check as one command in `.`, or a review when none is configured.
     * A blank command is treated as no check, matching a Project task check.
     *
     * @return list<array<string, string>>
     */
    private static function deliverables(?string $taskCheck): array
    {
        $command = is_string($taskCheck) ? trim($taskCheck) : '';
        $deliverable = $command === ''
            ? new TaskDeliverable(
                id: 'fixup-review',
                type: TaskDeliverableType::Review,
                description: 'Confirm the conflict or failed check is resolved from the available evidence.',
            )
            : new TaskDeliverable(
                id: 'project-check',
                type: TaskDeliverableType::Command,
                description: 'Run the Project task check',
                command: $command,
                directory: '.',
            );

        $encoded = [];
        foreach ($deliverable->toArray() as $key => $value) {
            if (! is_string($value)) {
                throw new \LogicException('A fixup deliverable field must be a string.');
            }

            $encoded[$key] = $value;
        }

        return [$encoded];
    }
}
