<?php

declare(strict_types=1);

namespace App\Domain\AppDefinitions;

use App\Domain\Shared\ResourceOperationException;
use Throwable;

final readonly class AppDefinitionConflict
{
    public static function nameTaken(string $kind, ?Throwable $previous = null): never
    {
        throw new ResourceOperationException(
            errorCode: "{$kind}_definition.name_taken",
            message: "An App {$kind} definition already uses this name.",
            status: 409,
            previous: $previous,
        );
    }
}
