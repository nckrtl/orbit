<?php

declare(strict_types=1);

namespace App\Support\Tui\Sources;

/**
 * Deployment history for one AppInstance, as `orbit top`'s Deployments pane would list it.
 *
 * The Gateway does not yet expose `GET /app-instances/{instance}/deployments` or
 * `GET /deployments/{id}`; a parallel slice is adding them. `forInstance()` returns `null` until
 * then, and the Deployments pane renders "Not available on this Gateway yet." instead of a
 * table. Wire the real SDK request by replacing `NullDeploymentsSource` with an implementation
 * that calls the new request and maps its response into the same row shape.
 */
interface DeploymentsSource
{
    /**
     * @return list<array{
     *     release: string,
     *     branch: string,
     *     commit: string,
     *     started: string,
     *     duration: string,
     *     status: string,
     *     failed_step: string|null,
     *     by: string,
     * }>|null Null when this Gateway cannot list deployment history yet.
     */
    public function forInstance(int $instanceId): ?array;
}
