<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
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

    /** The whole seconds of the grace period left after a name moved at `$movedAt`. */
    public function remainingAfter(?CarbonInterface $movedAt): int
    {
        if (! $movedAt instanceof CarbonInterface) {
            return self::WithdrawalGraceSeconds;
        }

        return max(0, self::WithdrawalGraceSeconds - (Carbon::now()->getTimestamp() - $movedAt->getTimestamp()));
    }

    /** Waits until the grace period after the latest of the given moves has passed. */
    public function waitAfter(?CarbonInterface ...$movedAt): void
    {
        $remaining = array_reduce(
            $movedAt,
            fn (int $longest, ?CarbonInterface $moved): int => max($longest, $this->remainingAfter($moved)),
            0,
        );

        if ($remaining > 0) {
            Sleep::for($remaining)->seconds();
        }
    }
}
