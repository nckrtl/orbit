<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Extensions\ExtensionStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequireEnabledExtension
{
    public function __construct(private ExtensionStore $extensions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $name = (string) $request->route()?->getName();
        $extension = str_starts_with($name, 'tasks:') ? 'tasks' : (str_starts_with($name, 'proxycli:') ? 'proxycli' : null);

        if ($extension !== null && ! in_array($name, ['tasks:status'], true) && ! $this->extensions->enabled($extension)) {
            return response()->json([
                'status' => 409,
                'error' => [
                    'code' => 'extension.disabled',
                    'message' => "The {$extension} extension is disabled.",
                    'details' => [],
                ],
            ], 409);
        }

        return $next($request);
    }
}
