<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

/**
 * The web app build that ships with a Gateway release. Prepare installs it before anything goes
 * live, so a missing build fails the prepare. Activation publishes it only after the Gateway
 * release verified, and restores the previous build when the release switches back.
 */
interface GatewayReleaseWebBuild
{
    /**
     * @return bool false when this Gateway installs no web build with its releases
     *
     * @throws GatewayReleaseException when the build for the commit cannot be installed
     */
    public function install(string $id, string $sha): bool;

    /** @throws GatewayReleaseException */
    public function publish(string $id): void;

    /** @throws GatewayReleaseException */
    public function restore(string $id): void;
}
