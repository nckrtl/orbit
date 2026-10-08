<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseException;

/** Runs a release step that must fail and returns its exception. */
function release_failure(Closure $operation): GatewayReleaseException
{
    try {
        $operation();
    } catch (GatewayReleaseException $exception) {
        return $exception;
    }

    throw new RuntimeException('The release step did not fail.');
}
