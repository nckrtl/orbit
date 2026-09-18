<?php

declare(strict_types=1);

namespace App\Http\Mcp;

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
            content: $sendsBody ? json_encode((object) $arguments, JSON_THROW_ON_ERROR) : null,
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

    private function body(Response $response): string
    {
        if (! $response instanceof StreamedResponse) {
            return (string) $response->getContent();
        }

        ob_start();

        try {
            $response->sendContent();
        } finally {
            $body = (string) ob_get_clean();
        }

        return $body;
    }
}
