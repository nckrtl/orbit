<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Document JSON and MCP envelopes enforce their own bounded limits after authorization. */
final class ValidateDocumentPostSize extends ValidatePostSize
{
    /** @param Closure(Request): Response $next */
    public function handle($request, Closure $next): Response
    {
        if (self::preservesExactJson($request)) {
            return $next($request);
        }
        $response = parent::handle($request, $next);
        if (! $response instanceof Response) {
            throw new \LogicException('HTTP middleware must return a response.');
        }

        return $response;
    }

    public static function preservesExactJson(Request $request): bool
    {
        return self::isDocumentRequest($request) || in_array($request->path(), ['mcp', 'mcp/search'], true);
    }

    public static function isDocumentRequest(Request $request): bool
    {
        return preg_match('~\Aapi/v1/projects/[0-9]+/documents(?:/|\z)~D', $request->path()) === 1;
    }
}
