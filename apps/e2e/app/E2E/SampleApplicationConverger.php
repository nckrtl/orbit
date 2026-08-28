<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\ConvergenceReport;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\TopologyTarget;
use RuntimeException;

final readonly class SampleApplicationConverger
{
    public function __construct(
        private IncusHost $host,
    ) {}

    public function converge(TopologyTarget $target, LaravelRelease $release): ConvergenceReport
    {
        $steps = [];

        foreach (['app-dev', 'app-prod'] as $role) {
            $instance = $target->instance($role);
            $result = $this->host->exec(
                $instance,
                new GuestCommand(['/usr/local/bin/converge-sample-app.sh', 'hydrate', $release->commit, $role], 900),
            );

            if (! $result->successful()) {
                throw new RuntimeException("Sample application convergence failed on {$instance}.");
            }

            $steps[$role.'.checkout'] = true;
        }

        $steps['release.pinned'] = true;
        $steps['permissions.normalized'] = true;

        return ConvergenceReport::successful($steps);
    }
}
