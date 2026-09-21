<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

/**
 * One driver read of an App instance's tracked site.
 *
 * A failed read has no visitor numbers, so a missing key cannot look like an empty site.
 */
final readonly class AnalyticsStatsRead
{
    /**
     * @param  list<AnalyticsPageStat>  $pages
     */
    public function __construct(
        public bool $readable,
        public string $siteDomain,
        public ?int $liveVisitors,
        public ?int $visitors24h,
        public ?int $visitors7d,
        public ?int $visitors30d,
        public array $pages,
        public ?string $errorCode,
        public ?string $error,
    ) {}

    public static function failed(string $siteDomain, string $errorCode, string $error): self
    {
        return new self(
            readable: false,
            siteDomain: $siteDomain,
            liveVisitors: null,
            visitors24h: null,
            visitors7d: null,
            visitors30d: null,
            pages: [],
            errorCode: $errorCode,
            error: $error,
        );
    }

    /** @param list<AnalyticsPageStat> $pages */
    public static function of(
        string $siteDomain,
        int $liveVisitors,
        int $visitors24h,
        int $visitors7d,
        int $visitors30d,
        array $pages,
    ): self {
        return new self(
            readable: true,
            siteDomain: $siteDomain,
            liveVisitors: $liveVisitors,
            visitors24h: $visitors24h,
            visitors7d: $visitors7d,
            visitors30d: $visitors30d,
            pages: $pages,
            errorCode: null,
            error: null,
        );
    }
}
