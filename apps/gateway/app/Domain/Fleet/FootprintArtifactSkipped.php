<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use RuntimeException;

/**
 * An artifact could not be published for a reason that is not Orbit's footprint, such as a user's Route
 * that makes the Node's Caddyfile unbuildable. The footprint records the artifact as `skipped` with the
 * reason and goes on; the artifact's own Doctor check keeps reporting the drift.
 */
final class FootprintArtifactSkipped extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
