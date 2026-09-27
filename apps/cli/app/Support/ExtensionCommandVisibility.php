<?php

declare(strict_types=1);

namespace App\Support;

use Closure;

final class ExtensionCommandVisibility
{
    private static bool $listing = false;

    public static function listing(): bool
    {
        return self::$listing;
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public static function duringListing(Closure $callback): mixed
    {
        $wasListing = self::$listing;
        self::$listing = true;

        try {
            return $callback();
        } finally {
            self::$listing = $wasListing;
        }
    }
}
