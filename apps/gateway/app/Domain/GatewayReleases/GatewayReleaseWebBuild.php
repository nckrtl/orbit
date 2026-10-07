<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

/**
 * The web app build that ships with a Gateway release. Prepare installs it before anything goes
 * live, so a missing build fails the prepare. Activation publishes it only after the Gateway
 * release verified, and restores the build that was current before when the release switches back.
 * Pruning a Gateway release removes its web build too, and a build that belongs to no retained release.
 */
interface GatewayReleaseWebBuild
{
    /**
     * Installs the build of the commit as the web release `<id>`. A complete install is reused.
     *
     * @return bool true when the build was already installed
     *
     * @throws GatewayReleaseException when the build for the commit cannot be installed
     */
    public function install(string $id, string $sha): bool;

    /** @throws GatewayReleaseException */
    public function publish(string $id): void;

    /**
     * Undoes the publish of this attempt: the web app serves the build it served before, which is
     * the build of the previous release `$id` when that release published it.
     *
     * @throws GatewayReleaseException
     */
    public function restore(string $id): void;

    /** Removes the web build of a pruned release unless the web app still serves it. */
    public function remove(string $id): void;

    /**
     * Removes every web build that belongs to no retained release, such as the build of a commit whose prepare failed
     * after the web install, unless the web app still serves it.
     *
     * @param  list<string>  $retained  The ids of the retained releases. An empty list prunes nothing.
     */
    public function prune(array $retained): void;
}
