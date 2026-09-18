<?php

declare(strict_types=1);

namespace App\Support\Tui\Sources\Concerns;

use Orbit\Sdk\GatewayRequest;
use Saloon\Http\PendingRequest;

/**
 * Caps one request's total time at a few seconds. `orbit top`'s RefreshScheduler runs these
 * per-record background requests (node metrics, deployment history, database users) one at a
 * time between frames; the connector's own default timeout (900s, for a deploy stream) would
 * let one slow or unreachable node stall the whole screen. This is CLI-side request middleware,
 * not an SDK or Gateway change: `GatewayRequest` already exposes `middleware()` from Saloon's
 * `Request` base class.
 */
trait LimitsBackgroundRequestTime
{
    private const float BACKGROUND_REQUEST_TIMEOUT_SECONDS = 3.0;

    private static function withBackgroundTimeout(GatewayRequest $request): GatewayRequest
    {
        $request->middleware()->onRequest(static function (PendingRequest $pendingRequest): void {
            $pendingRequest->config()->add('timeout', self::BACKGROUND_REQUEST_TIMEOUT_SECONDS);
        });

        return $request;
    }
}
