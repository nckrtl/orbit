<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Runs after active-peer authorization, before MCP decodes the JSON-RPC envelope. */
final class ValidateMcpPostSize
{
    private const int MAX_BYTES = 15728640;

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $length = $request->server('CONTENT_LENGTH');
        if ((is_numeric($length) && (float) $length > self::MAX_BYTES) || strlen($request->getContent()) > self::MAX_BYTES) {
            throw new HttpException(413, 'MCP request is too large.');
        }

        return $next($request);
    }
}
