<?php

declare(strict_types=1);

namespace App\Actions\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Infrastructure\GatewayReleases\GatewayReleaseBuilder;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;

/**
 * Caches the current release's configuration again from the shared env file. A release runs with
 * a cached configuration, so an env change takes effect only after this. The new cache replaces
 * the old one in one rename, so requests in flight never read half of it.
 */
final readonly class ConfigureGatewayReleaseAction
{
    public function __construct(
        private GatewayReleaseLock $lock,
        private GatewayReleaseLayout $layout,
        private GatewayReleaseBuilder $builder,
    ) {}

    /** @return array{release: string, sha: string, duration_ms: int} */
    public function execute(): array
    {
        return $this->lock->run(function (): array {
            $startedAt = hrtime(true);
            $id = $this->layout->currentReleaseId();
            $sha = $id === null ? null : $this->layout->preparedCommit($id);

            if ($id === null || $sha === null) {
                throw new GatewayReleaseException(
                    step: 'configuration',
                    errorCode: 'gateway.release_not_adopted',
                    message: 'The Gateway is not running from a release. Run gateway:release:adopt first.',
                );
            }

            $this->builder->refreshConfiguration($id);

            return ['release' => $id, 'sha' => $sha, 'duration_ms' => intdiv(hrtime(true) - $startedAt, 1_000_000)];
        });
    }
}
