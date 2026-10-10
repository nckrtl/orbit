<?php

declare(strict_types=1);

namespace App\Http\Mcp;

use App\Domain\Tasks\TaskDefinitionJson;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Runs one API operation as an internal request on behalf of the MCP caller.
 *
 * The internal request carries the caller's own REMOTE_ADDR and travels through the HTTP kernel, so the
 * active WireGuard peer check, directed node access, validation, redaction, request correlation, and
 * command activity all apply exactly as they do to the same call made over HTTP by the CLI.
 */
final readonly class ApiDispatcher
{
    public function __construct(private Application $app, private Kernel $kernel) {}

    /** @param array<string, mixed> $arguments */
    public function dispatch(ToolDefinition $definition, array $arguments, Request $caller): ApiResult
    {
        $arguments = $this->argumentsKeepingObjects($definition, $arguments, $caller->getContent());
        $path = $definition->path;
        $query = [];

        foreach ($definition->pathInputs as $input) {
            $value = $arguments[$input] ?? null;
            $path = str_replace('{'.$input.'}', rawurlencode(is_scalar($value) ? (string) $value : ''), $path);
            unset($arguments[$input]);
        }

        foreach ($definition->queryInputs as $input) {
            if (array_key_exists($input, $arguments)) {
                $query[$input] = $arguments[$input];
                unset($arguments[$input]);
            }
        }

        $sendsBody = ! in_array($definition->method, ['GET', 'DELETE'], true) || $arguments !== [];
        $uri = $query === [] ? $path : $path.'?'.http_build_query($query);

        $request = Request::create(
            uri: $uri,
            method: $definition->method,
            server: [
                'REMOTE_ADDR' => $caller->server('REMOTE_ADDR'),
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_HOST' => $caller->getHttpHost(),
                'HTTPS' => $caller->isSecure() ? 'on' : 'off',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_ORBIT_CLIENT' => 'mcp',
            ],
            // Null content makes Symfony read php://input, which under PHP-FPM is the caller's JSON-RPC body.
            content: $sendsBody ? json_encode((object) $arguments, JSON_THROW_ON_ERROR) : '',
        );

        try {
            $response = $this->kernel->handle($request);

            return new ApiResult($response->getStatusCode(), $this->body($response));
        } finally {
            // The kernel rebinds the container's request; the MCP transport still answers the caller's.
            $this->app->instance('request', $caller);
            Facade::clearResolvedInstance('request');
        }
    }

    /**
     * Laravel MCP decodes the JSON-RPC body associatively, which turns `{}` into a list.
     * The caller's raw `params.arguments` still has the objects, so the API body keeps them.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function argumentsKeepingObjects(ToolDefinition $definition, array $arguments, string $raw): array
    {
        $decoded = TaskDefinitionJson::decode($raw);
        $params = is_array($decoded) ? ($decoded['params'] ?? null) : null;

        if (! is_array($params) || ($params['name'] ?? null) !== $definition->name || ! is_array($params['arguments'] ?? null) || array_is_list($params['arguments'])) {
            return $arguments;
        }

        $preserved = [];

        foreach ($params['arguments'] as $key => $value) {
            if (is_string($key)) {
                $preserved[$key] = $value;
            }
        }

        return $preserved;
    }

    /**
     * A streamed operation, such as a deploy, flushes each event line. Flushing a plain buffer would pass the
     * lines on to the MCP reply before its headers, so PHP would send them as text/html. The capturing
     * handler keeps every flushed chunk in the tool result and passes nothing on.
     */
    private function body(Response $response): string
    {
        if (! $response instanceof StreamedResponse) {
            return (string) $response->getContent();
        }

        $body = '';

        ob_start(static function (string $chunk) use (&$body): string {
            $body .= $chunk;

            return '';
        });

        try {
            $response->sendContent();
        } finally {
            ob_end_flush();
        }

        return $body;
    }
}
