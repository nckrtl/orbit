<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Models\Node;

interface CaddyBuildInspector
{
    /**
     * Compares the Node's live Caddyfile with a fresh Node Caddy build. Null means the Node has no build to
     * compare: it serves no Caddy site and no role on it publishes one.
     *
     * @throws DoctorInspectionException
     */
    public function inspect(Node $node): ?CaddyBuildObservation;
}
