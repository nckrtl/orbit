<?php

declare(strict_types=1);

namespace App\Domain\ProjectDefinitions;

use App\Domain\Shared\ResourceOperationException;

final readonly class ProjectDefinitionConflict
{
    public static function nameTaken(string $kind): never
    {
        throw new ResourceOperationException(
            errorCode: "{$kind}_definition.name_taken",
            message: "A Project {$kind} definition already uses this name.",
            status: 409,
        );
    }
}
