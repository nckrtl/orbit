<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use RuntimeException;

/** A task row used a column, or a child, that its level does not have. */
final class TaskHierarchyException extends RuntimeException
{
    public static function column(string $column, string $level): self
    {
        return new self("A {$level} cannot set {$column}.");
    }

    public static function nested(): self
    {
        return new self('A subtask cannot have subtasks.');
    }
}
