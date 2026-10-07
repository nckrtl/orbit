<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\GatewayReleases\GatewayReleaseWebBuild;

/** A release pipeline without a web build, for tests about the Gateway release alone. */
final class NoWebBuild implements GatewayReleaseWebBuild
{
    public function install(string $id, string $sha): bool
    {
        return true;
    }

    public function publish(string $id): void {}

    public function restore(string $id): void {}

    public function remove(string $id): void {}
}
