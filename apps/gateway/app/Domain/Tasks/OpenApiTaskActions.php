<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * Operations the OpenAPI document marks with x-orbit-task-action.
 * An action subtask may call only one of these.
 */
final class OpenApiTaskActions
{
    /** @var list<string>|null */
    private ?array $names = null;

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

        $path = dirname(base_path(), 2).'/docs/openapi.json';
        $contents = is_file($path) ? file_get_contents($path) : false;

        if (! is_string($contents)) {
            return $this->names = [];
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            return $this->names = [];
        }

        $paths = $decoded['paths'] ?? null;

        if (! is_array($paths)) {
            return $this->names = [];
        }

        $names = [];

        foreach ($paths as $pathItem) {
            if (! is_array($pathItem)) {
                continue;
            }

            foreach (['get', 'post', 'put', 'patch', 'delete', 'head'] as $method) {
                $operation = $pathItem[$method] ?? null;

                if (! is_array($operation) || ($operation['x-orbit-task-action'] ?? false) !== true) {
                    continue;
                }

                $operationId = $operation['operationId'] ?? null;

                if (is_string($operationId) && $operationId !== '') {
                    $names[] = $operationId;
                }
            }
        }

        return $this->names = array_values(array_unique($names));
    }
}
