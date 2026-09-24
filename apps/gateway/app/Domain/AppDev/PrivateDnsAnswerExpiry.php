<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

use Illuminate\Support\Sleep;

/**
 * Private DNS answers carry a fixed TTL. A transition that has moved a name to its new target waits
 * until every cached answer for the old target can have expired, and only then stops serving the
 * old target.
 */
final readonly class PrivateDnsAnswerExpiry
{
    /** The TTL of every answer the Orbit private DNS listener returns. */
    public const int TtlSeconds = 30;

    /**
     * The grace period between moving a name and withdrawing its old target: the answer TTL plus
     * one second for a query answered just before the publication. It covers resolvers that honor
     * the TTL. Caddy finishes in-flight requests on the old target when it reloads and closes idle
     * keep-alive connections, so their next request resolves again.
     */
    public const int WithdrawalGraceSeconds = self::TtlSeconds + 1;

    public function wait(): void
    {
        Sleep::for(self::WithdrawalGraceSeconds)->seconds();
    }
}
