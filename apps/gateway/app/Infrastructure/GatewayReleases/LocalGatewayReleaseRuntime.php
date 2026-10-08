<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseRuntime;

/**
 * The runtime handoff in the running process, with its own code, for a release whose code may predate the handoff
 * command: adoption's first release, built from the in-place checkout's commit. The handoff works on the stable
 * Gateway path, so it applies to whichever release that path links to.
 */
final readonly class LocalGatewayReleaseRuntime implements GatewayReleaseRuntime
{
    public function __construct(private GatewayRuntimeHandoff $handoff) {}

    public function handoff(string $id): array
    {
        return $this->handoff->serve();
    }

    public function schedule(string $id): array
    {
        return $this->handoff->schedule();
    }
}
