<?php

declare(strict_types=1);

namespace App\Support;

use Laravel\Boost\Install\Agents\Codex;

final class LaravelZeroCodex extends Codex
{
    /** @mago-expect lint:no-boolean-flag-parameter Laravel Boost requires this inherited signature. */
    public function getArtisanPath(bool $forceAbsolutePath = false): string
    {
        return $forceAbsolutePath ? base_path('orbit') : 'orbit';
    }
}
