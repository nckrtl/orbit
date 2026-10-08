<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Infrastructure\Processes\CommandDeadline;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Starts the command deadline for an MCP request. Each tool call runs as a nested API request, which
 * cannot extend or clear this deadline, so a batch of tool calls together ends before PHP-FPM ends
 * the request.
 */
final readonly class StartRequestDeadline
{
    public function __construct(private CommandDeadline $deadline) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $endDeadline = $this->deadline->startRequest(Config::float('orbit.command_timeout', 570.0), CommandDeadline::CleanupReserveSeconds);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $endDeadline();

            throw $exception;
        }

        if (! $response instanceof StreamedResponse) {
            $endDeadline();

            return $response;
        }

        // A streamed MCP reply runs its tool calls while it sends, after this middleware returns.
        $callback = $response->getCallback();
        $response->setCallback(static function () use ($callback, $endDeadline): void {
            try {
                if ($callback instanceof Closure) {
                    $callback();
                }
            } finally {
                $endDeadline();
            }
        });

        return $response;
    }
}
