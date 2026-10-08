<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Nodes\NodeProvisioningException;

/** Installs and starts `orbit-fleet-converge.service` and its timer on the Gateway host. */
interface FleetConvergeUnits
{
    /**
     * Installs the service and the timer, and enables the timer. `orbit:gateway-web` and the release
     * runtime handoff run it.
     *
     * @throws NodeProvisioningException
     */
    public function converge(): void;

    /**
     * Starts the service without waiting for it, so the caller never runs the rollout itself. Returns
     * false when systemd refused; the timer then starts it within 5 minutes.
     */
    public function start(): bool;

    /**
     * Starts the service again shortly after the running one exits. A `start` on a running oneshot joins its
     * job and would start nothing new, so a pass that finds a newer release current asks for a later start.
     */
    public function startLater(): bool;
}
