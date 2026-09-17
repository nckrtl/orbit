<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * The API reference declares `error.details` as an object. PHP encodes an empty
 * details array as a JSON list, so this outermost middleware rewrites that one
 * case to an empty object after any error envelope has been rendered.
 */
final class NormalizeErrorDetails
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response instanceof JsonResponse) {
            $data = $response->getData();

            if (is_object($data) && isset($data->error) && is_object($data->error)
                && property_exists($data->error, 'details') && $data->error->details === []) {
                $data->error->details = new stdClass;
                $response->setData($data);
            }
        }

        return $response;
    }
}
