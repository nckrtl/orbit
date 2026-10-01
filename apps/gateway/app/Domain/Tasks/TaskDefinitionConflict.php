<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Shared\ResourceOperationException;

final class TaskDefinitionConflict
{
    public static function nameTaken(string $name): never
    {
        throw new ResourceOperationException(
            errorCode: 'tasks.definition_exists',
            message: 'The Project already uses this task definition name.',
            status: 409,
            details: ['name' => $name],
        );
    }
}
