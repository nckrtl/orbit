<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use RuntimeException;

final class TaskDefinitionInvalid extends RuntimeException
{
    public const string CODE = 'tasks.definition_invalid';

    /** @param list<TaskDefinitionViolation> $rules */
    public function __construct(public readonly array $rules)
    {
        parent::__construct('The task definition is invalid.');
    }
}
