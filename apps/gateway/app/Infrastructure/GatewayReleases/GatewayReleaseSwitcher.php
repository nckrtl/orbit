<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;

/**
 * Points `/home/orbit/orbit` at one prepared release with a single `mv -T`, so readers see either
 * the previous release or the next one and never a missing path.
 */
final readonly class GatewayReleaseSwitcher
{
    public function __construct(
        private GatewayReleaseLayout $layout,
        private ProcessRunner $processes,
    ) {}

    /** @return string|null the release id that was current before this call */
    public function switchTo(string $id): ?string
    {
        $id = GatewayReleaseCommit::assertId($id);

        if ($this->layout->preparedCommit($id) === null) {
            throw new GatewayReleaseException(
                step: 'switch',
                errorCode: 'gateway.release_not_prepared',
                message: "Release [{$id}] is not prepared.",
                status: 422,
            );
        }

        $current = $this->layout->currentPath();
        $previous = $this->layout->currentReleaseId();

        if ($previous === $id) {
            return $previous;
        }

        if ((file_exists($current) && ! is_link($current)) || (is_link($current) && $previous === null)) {
            throw new GatewayReleaseException(
                step: 'switch',
                errorCode: 'gateway.release_not_adopted',
                message: "[{$current}] is an in-place checkout or a link to something other than a release. Run gateway:release:adopt before deploying a release.",
            );
        }

        $next = $current.'.next';

        if (file_exists($next) || is_link($next)) {
            @unlink($next);
        }

        if (! @symlink($this->layout->linkTarget($id), $next)) {
            throw new GatewayReleaseException(
                step: 'switch',
                errorCode: 'gateway.release_switch_failed',
                message: "The next release link [{$next}] cannot be created.",
                status: 500,
            );
        }

        $result = $this->processes->run(new ProcessInvocation(['mv', '-Tf', '--', $next, $current], timeout: 30.0));

        if (! $result->succeeded()) {
            @unlink($next);

            throw new GatewayReleaseException(
                step: 'switch',
                errorCode: 'gateway.release_switch_failed',
                message: "The current release link [{$current}] cannot be switched to [{$id}].",
                status: 500,
                result: $result,
            );
        }

        return $previous;
    }
}
