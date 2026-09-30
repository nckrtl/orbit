<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use RuntimeException;

/**
 * Operations the generated task-action list allows.
 * bin/mcp-tools writes resources/tasks/actions.json from the OpenAPI marks.
 * An action subtask may call only one of these.
 */
final class OpenApiTaskActions
{
    /** @var list<string>|null */
    private ?array $names = null;

    public function __construct(private readonly ?string $path = null) {}

    public function allows(string $operation): bool
    {
        if (! str_contains($operation, ':')) {
            return false;
        }

        return in_array(str_replace(':', '-', $operation), $this->names(), true);
    }

    /** @return list<string> */
    public function names(): array
    {
        if ($this->names !== null) {
            return $this->names;
        }

        $path = $this->path ?? resource_path('tasks/actions.json');
        $contents = is_file($path) ? file_get_contents($path) : false;

        if (! is_string($contents)) {
            throw new RuntimeException("The task action list is missing at {$path}.");
        }

        $decoded = json_decode($contents, true);
        $actions = is_array($decoded) ? ($decoded['actions'] ?? null) : null;

        if (! is_array($actions)) {
            throw new RuntimeException("The task action list at {$path} is not valid.");
        }

        $names = [];

        foreach ($actions as $name) {
            if (! is_string($name) || $name === '') {
                throw new RuntimeException("The task action list at {$path} is not valid.");
            }

            $names[] = $name;
        }

        return $this->names = array_values(array_unique($names));
    }
}
