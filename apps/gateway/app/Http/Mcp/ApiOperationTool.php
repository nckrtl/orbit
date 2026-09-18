<?php

declare(strict_types=1);

namespace App\Http\Mcp;

use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/** One Gateway API operation offered as an MCP tool. */
final class ApiOperationTool extends Tool
{
    public function __construct(
        private readonly ToolDefinition $definition,
        private readonly ApiDispatcher $dispatcher,
    ) {
        $this->name = $definition->name;
        $this->title = $definition->title;
        $this->description = $definition->description;
    }

    public function handle(Request $request, HttpRequest $caller): Response
    {
        /** @var array<string, mixed> $arguments */
        $arguments = $request->all();
        $result = $this->dispatcher->dispatch($this->definition, $arguments, $caller);
        $json = $result->json();

        if ($result->failed()) {
            return Response::error($json === null
                ? "HTTP {$result->status}"
                : (string) json_encode(['status' => $result->status, ...$json], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        if ($json === null) {
            return Response::text(trim($result->body) === '' ? "Done (HTTP {$result->status})." : $result->body);
        }

        return Response::json($json);
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function toArray(): array
    {
        $schema = $this->definition->inputSchema;
        $schema['properties'] = (object) ($schema['properties'] ?? []);

        return [
            'name' => $this->definition->name,
            'title' => $this->definition->title,
            'description' => $this->definition->description,
            'inputSchema' => $schema,
            'annotations' => [
                'readOnlyHint' => $this->definition->readsOnly(),
                'destructiveHint' => $this->definition->method === 'DELETE',
                'idempotentHint' => in_array($this->definition->method, ['GET', 'PUT', 'DELETE'], true),
                'openWorldHint' => false,
            ],
        ];
    }
}
