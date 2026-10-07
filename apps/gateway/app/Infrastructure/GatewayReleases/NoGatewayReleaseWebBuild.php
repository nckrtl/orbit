<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseWebBuild;

/** Releases leave the web app as it is; `bin/web-deploy` publishes it separately. */
final readonly class NoGatewayReleaseWebBuild implements GatewayReleaseWebBuild
{
    public function install(string $id, string $sha): bool
    {
        return false;
    }

    public function publish(string $id): void {}

    public function restore(string $id): void {}
}
