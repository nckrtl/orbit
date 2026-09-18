<?php

declare(strict_types=1);

namespace App\Http\Mcp;

use InvalidArgumentException;

/** One entry of resources/mcp/tools.json: the API operation an MCP tool calls. */
final readonly class ToolDefinition
{
    /**
     * @param  list<string>  $pathInputs
     * @param  list<string>  $queryInputs
     * @param  array<string, mixed>  $inputSchema
     */
    public function __construct(
        public string $name,
        public string $title,
        public string $description,
        public string $method,
        public string $path,
        public array $pathInputs,
        public array $queryInputs,
        public bool $streams,
        public array $inputSchema,
    ) {}

    /** @param array<array-key, mixed> $entry */
    public static function fromArray(array $entry): self
    {
        $name = $entry['name'] ?? null;
        $title = $entry['title'] ?? null;
        $description = $entry['description'] ?? null;
        $method = $entry['method'] ?? null;
        $path = $entry['path'] ?? null;
        $pathInputs = $entry['path_inputs'] ?? null;
        $queryInputs = $entry['query_inputs'] ?? null;
        $inputSchema = $entry['input_schema'] ?? null;

        if (
            ! is_string($name) || ! is_string($title) || ! is_string($description)
            || ! is_string($method) || ! is_string($path)
            || ! is_array($pathInputs) || ! is_array($queryInputs) || ! is_array($inputSchema)
        ) {
            throw new InvalidArgumentException('The MCP tool manifest has a malformed entry.');
        }

        /** @var array<string, mixed> $inputSchema */
        return new self(
            name: $name,
            title: $title,
            description: $description,
            method: $method,
            path: $path,
            pathInputs: array_values(array_filter($pathInputs, is_string(...))),
            queryInputs: array_values(array_filter($queryInputs, is_string(...))),
            streams: ($entry['streams'] ?? false) === true,
            inputSchema: $inputSchema,
        );
    }

    public function readsOnly(): bool
    {
        return $this->method === 'GET';
    }
}
