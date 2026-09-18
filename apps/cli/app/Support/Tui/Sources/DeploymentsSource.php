<?php

declare(strict_types=1);

namespace App\Support\Tui\Sources;

/**
 * Deployment history for one AppInstance, as `orbit top`'s Deployments pane lists it and its
 * deployment record page shows in full.
 *
 * `Sources\GatewayDeploymentsSource` calls `GET /instances/{instance}/deployments`. `forInstance()`
 * returns `null` when the Gateway request fails (an older Gateway that does not expose deployment
 * history, for example), and the Deployments pane renders "Not available on this Gateway yet."
 * instead of a table.
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
