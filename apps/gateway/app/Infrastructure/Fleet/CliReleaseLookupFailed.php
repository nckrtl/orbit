<?php

declare(strict_types=1);

namespace App\Infrastructure\Fleet;

use App\Domain\Fleet\CliReleaseUnavailableReason;
use RuntimeException;

/** Ends a CLI release lookup with the reason the release is unavailable. It never leaves the catalog. */
final class CliReleaseLookupFailed extends RuntimeException
{
    public function __construct(public readonly CliReleaseUnavailableReason $reason)
    {
        parent::__construct('The CLI release is unavailable: '.$reason->value.'.');
    }
}
