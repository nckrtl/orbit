<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Metrics;

use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Shared\ResourceOperationException;

interface NodeFleetMetricsReader
{
    /**
     * Reads one metrics snapshot per Node the Metrics role's Prometheus has samples for.
     *
     * @throws ResourceOperationException When Metrics is not assigned, the
     *                                    Metrics Node is not active, or the
     *                                    Metrics Node could not be read.
     * @throws RoleAssignmentException When more than one Node carries the
     *                                 Metrics role.
     */
    public function read(): NodeFleetMetricsSnapshot;
}
