<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;

/** Sends trusted metadata IO from the Gateway; no managed-user program is loaded from the checkout. */
final readonly class TaskWorkspaceMetadata
{
    public static function bashPreamble(): string
    {
        $program = file_get_contents(resource_path('tasks/metadata'));
        if ($program === false) {
            throw new RuntimeConvergenceException(
                step: 'task-workspace-metadata',
                errorCode: 'tasks.metadata_failed',
                message: 'The task metadata program is missing from the Gateway.',
            );
        }

        return 'workspace_metadata() { python3 -I -c '.escapeshellarg($program).' "$checkout" "$@"; }'."\n";
    }

    /** @param array<string, mixed> $payload */
    public static function operation(string $operation, array $payload = []): string
    {
        $encoded = base64_encode(json_encode($payload === [] ? (object) [] : $payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return "printf '%s' '{$encoded}' | base64 -d | workspace_metadata ".escapeshellarg($operation)."\n";
    }
}
