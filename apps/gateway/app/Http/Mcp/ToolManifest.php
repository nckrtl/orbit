<?php

declare(strict_types=1);

namespace App\Http\Mcp;

use App\Domain\Extensions\ExtensionStore;
use InvalidArgumentException;
use JsonException;
use stdClass;

/**
 * The generated list of API operations the MCP server exposes as tools.
 *
 * `bin/mcp-tools` writes resources/mcp/tools.json from docs/openapi.json, so the tool catalogue is the
 * API catalogue: one tool for every operation an operator can run.
 */
final readonly class ToolManifest
{
    public function __construct(private string $path) {}

    public static function default(): self
    {
        return new self(resource_path('mcp/tools.json'));
    }

    /** @return list<ToolDefinition> */
    public function definitions(): array
    {
        $contents = @file_get_contents($this->path);

        if (! is_string($contents)) {
            throw new InvalidArgumentException("The MCP tool manifest is missing at {$this->path}.");
        }

        try {
            // Associative decoding turns {} into []. Keep an empty object as stdClass so the
            // catalogue encodes it as {} again. A list stays a list.
            $decoded = self::preserveEmptyObjects(json_decode($contents, associative: false, flags: JSON_THROW_ON_ERROR));
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The MCP tool manifest is not valid JSON.', previous: $exception);
        }

        $tools = is_array($decoded) ? $decoded['tools'] ?? null : null;

        if (! is_array($tools)) {
            throw new InvalidArgumentException('The MCP tool manifest has no tools list.');
        }

        return array_values(array_map(
            static fn (mixed $entry): ToolDefinition => ToolDefinition::fromArray(is_array($entry) ? $entry : []),
            $tools,
        ));
    }

    /** @return list<ApiOperationTool> */
    public function tools(ApiDispatcher $dispatcher): array
    {
        $extensions = app(ExtensionStore::class);

        return array_values(array_map(
            static fn (ToolDefinition $definition): ApiOperationTool => new ApiOperationTool($definition, $dispatcher),
            array_filter($this->definitions(), static fn (ToolDefinition $definition): bool => $definition->extension === null || $extensions->enabled($definition->extension)),
        ));
    }

    /**
     * Objects stay arrays with string keys. An empty object stays stdClass, so encoding keeps {}.
     */
    private static function preserveEmptyObjects(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);

            if ($properties === []) {
                return new stdClass;
            }

            $object = [];

            foreach ($properties as $key => $item) {
                $object[$key] = self::preserveEmptyObjects($item);
            }

            return $object;
        }

        if (is_array($value)) {
            return array_map(self::preserveEmptyObjects(...), $value);
        }

        return $value;
    }
}
