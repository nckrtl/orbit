<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\Metrics\MetricsAccessRevoker;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Caddy\CaddyPublicationLock;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Models\NodeRole;

/**
 * Reloads the Gateway's Caddy after Node access changes, so an open Metrics connection authorizes again.
 * It holds the Node Caddy lock, so the reload never interleaves with a build's swap and restore.
 */
final readonly class NativeMetricsAccessRevoker implements MetricsAccessRevoker
{
    public function __construct(
        private ProcessRunner $processes,
    ) {}

    public function revoke(): void
    {
        $metricsMayBePublished = NodeRole::query()
            ->where('role', RoleName::Metrics->value)
            ->exists();

        if (! $metricsMayBePublished) {
            return;
        }

        $result = $this->processes->run(new ProcessInvocation(
            arguments: ['sudo', 'bash', '-seu'],
            timeout: 60.0,
            input: CaddyPublicationLock::script(CaddyPublicationLock::Path).PHP_EOL.'systemctl reload caddy'.PHP_EOL,
        ));

        if (! $result->succeeded()) {
            throw new ResourceOperationException(
                'metrics.caddy_publication_failed',
                'Metrics Caddy publication did not complete.',
                502,
            );
        }
    }
}
