<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

/**
 * What happened to the Gateway-side publication during a Metrics disable.
 *
 * Disabling Metrics with no single active Gateway still removes every node-side
 * effect, but the `metrics.orbit` route, its certificate and its DNS record are
 * bound to a Gateway nobody can name. Reporting them as un-cleaned tells the
 * operator what is left on the Gateway host.
 */
enum MetricsPublicationCleanup: string
{
    case Cleaned = 'cleaned';

    case Uncleaned = 'uncleaned';
}
