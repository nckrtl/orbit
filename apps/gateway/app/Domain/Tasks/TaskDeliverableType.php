<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * How Orbit checks one subtask deliverable
 * ([ADR 0133](/decisions/0133-verify-typed-subtask-deliverables-at-handoff)).
 */
enum TaskDeliverableType: string
{
    /** A path or glob that the subtask's diff creates or modifies. */
    case File = 'file';

    /** A Pest test in the subtask's diff that Orbit's check runs and that passes. */
    case Test = 'test';

    /** A command that Orbit's check runs and that exits with 0. */
    case Command = 'command';

    /** An item the reviewer confirms in its approval. */
    case Review = 'review';

    /** @return list<string> the fields this type adds to id, type, and description */
    public function fields(): array
    {
        return match ($this) {
            self::File => ['path', 'change'],
            self::Test => ['project', 'file', 'name', 'fails_on_base'],
            self::Command => ['command', 'directory'],
            self::Review => [],
        };
    }
}
