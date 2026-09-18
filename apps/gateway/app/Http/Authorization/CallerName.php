<?php

declare(strict_types=1);

namespace App\Http\Authorization;

use App\Models\Node;
use Illuminate\Http\Request;

/**
 * Resolves the calling Node's name as the Gateway knows it, for records that
 * name the API caller such as a deployment's `triggered_by` or a managed
 * database user's `created_by`. Orbit's binary directed node access means the
 * caller is always the authenticated Node, never a separate user identity.
 */
final readonly class CallerName
{
    public static function fromRequest(Request $request): string
    {
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return 'unknown';
        }

        return self::of($caller);
    }

    private static function of(Node $node): string
    {
        return $node->name;
    }
}
