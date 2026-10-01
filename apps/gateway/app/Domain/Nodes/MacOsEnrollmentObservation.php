<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Infrastructure\Ssh\HostKey;

/**
 * Platform, architecture, and host key observed while enrolling a Mac.
 *
 * The architecture is the raw `uname -m` value. Apple silicon stays `arm64`.
 */
final readonly class MacOsEnrollmentObservation
{
    public function __construct(
        public string $architecture,
        public HostKey $hostKey,
    ) {}
}
