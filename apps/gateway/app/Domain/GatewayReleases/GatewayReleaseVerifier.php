<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

/**
 * The deploy-verify checks for one release: `/up` is up, Gateway status is `ok`, and its version
 * is the release commit.
 *
 * @phpstan-type VerifyResult array{status: string, version: string}
 */
interface GatewayReleaseVerifier
{
    /**
     * @return VerifyResult
     */
    public function verify(string $sha): array;
}
