<?php

declare(strict_types=1);

namespace App\Support\Tui\Sources;

/**
 * Deployment history for one AppInstance, as `orbit top`'s Deployments pane lists it and its
 * deployment record page shows in full.
 *
 * `Sources\GatewayDeploymentsSource` calls `GET /instances/{instance}/deployments`. `forInstance()`
 * returns `null` when the request fails or times out, and the Deployments pane renders
 * "Deployment history unavailable right now." instead of a table.
 */
interface DeploymentsSource
{
    /**
     * @return list<array{
     *     id: int,
     *     release: string,
     *     branch: string,
     *     commit: string,
     *     started: string,
     *     finished: string,
     *     duration: string,
     *     status: string,
     *     failed_step: string|null,
     *     error_code: string|null,
     *     selected_release: string|null,
     *     by: string,
     * }>|null Null when this Gateway cannot list deployment history for this instance.
     */
    public function forInstance(int $instanceId): ?array;
}
