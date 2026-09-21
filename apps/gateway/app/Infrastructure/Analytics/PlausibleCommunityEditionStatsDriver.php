<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics;

use App\Domain\Analytics\AnalyticsPageStat;
use App\Domain\Analytics\AnalyticsStatsDriver;
use App\Domain\Analytics\AnalyticsStatsKeyStore;
use App\Domain\Analytics\AnalyticsStatsRead;
use App\Domain\Analytics\AnalyticsTrackingUpstream;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

/**
 * Reads the fleet Plausible Community Edition Stats API over WireGuard
 * ([ADR 0102](/decisions/0102-read-app-instance-analytics-through-a-fleet-driver)).
 */
final readonly class PlausibleCommunityEditionStatsDriver implements AnalyticsStatsDriver
{
    public const string Name = 'plausible_ce';

    private const float Timeout = 5.0;

    private const int PageLimit = 10;

    public function __construct(private AnalyticsStatsKeyStore $keys) {}

    public function name(): string
    {
        return self::Name;
    }

    public function fleetHealthy(): bool
    {
        return AnalyticsTrackingUpstream::current() !== null;
    }

    public function read(string $siteDomain): AnalyticsStatsRead
    {
        $key = $this->keys->get();

        if ($key === null) {
            return AnalyticsStatsRead::failed(
                $siteDomain,
                'analytics.stats_key_missing',
                'No Plausible Stats API key is stored. Create one in Plausible and store it with analytics:credentials.',
            );
        }

        $base = AnalyticsTrackingUpstream::current();

        if ($base === null) {
            return AnalyticsStatsRead::failed(
                $siteDomain,
                'analytics.stats_unreadable',
                'The analytics role has no reachable Plausible address.',
            );
        }

        $query = ['site_id' => $siteDomain];
        $aggregate = static fn (string $period): array => [
            ...$query,
            'period' => $period,
            'metrics' => 'visitors',
        ];

        $responses = Http::pool(fn (Pool $pool): array => [
            $this->request($pool, 'live', $base, $key)
                ->get('/api/v1/stats/realtime/visitors', $query),
            $this->request($pool, 'day', $base, $key)
                ->get('/api/v1/stats/aggregate', $aggregate('day')),
            $this->request($pool, 'week', $base, $key)
                ->get('/api/v1/stats/aggregate', $aggregate('7d')),
            $this->request($pool, 'month', $base, $key)
                ->get('/api/v1/stats/aggregate', $aggregate('30d')),
            $this->request($pool, 'pages', $base, $key)
                ->get('/api/v1/stats/breakdown', [
                    ...$query,
                    'period' => '30d',
                    'property' => 'event:page',
                    'metrics' => 'visitors',
                    'limit' => self::PageLimit,
                ]),
        ]);

        foreach ($responses as $response) {
            if (! $response instanceof Response) {
                return AnalyticsStatsRead::failed(
                    $siteDomain,
                    'analytics.stats_unreadable',
                    'Plausible did not answer the Stats API.',
                );
            }

            $failed = $this->failure($siteDomain, $response);

            if ($failed instanceof AnalyticsStatsRead) {
                return $failed;
            }
        }

        $live = $this->liveVisitors($responses['live']);
        $day = $this->aggregateVisitors($responses['day']);
        $week = $this->aggregateVisitors($responses['week']);
        $month = $this->aggregateVisitors($responses['month']);
        $pages = $this->pages($responses['pages']);

        if ($live === null || $day === null || $week === null || $month === null || $pages === null) {
            return AnalyticsStatsRead::failed(
                $siteDomain,
                'analytics.stats_unreadable',
                'Plausible returned a stats answer the Gateway could not read.',
            );
        }

        return AnalyticsStatsRead::of($siteDomain, $live, $day, $week, $month, $pages);
    }

    private function request(Pool $pool, string $name, string $base, #[SensitiveParameter] string $key): PendingRequest
    {
        return $pool->as($name)
            ->baseUrl('http://'.$base)
            ->withToken($key)
            ->acceptJson()
            ->timeout(self::Timeout);
    }

    private function failure(string $siteDomain, Response $response): ?AnalyticsStatsRead
    {
        if ($response->successful()) {
            return null;
        }

        if ($response->status() === 401) {
            return AnalyticsStatsRead::failed(
                $siteDomain,
                'analytics.stats_unauthorized',
                'Plausible rejected the stored Stats API key.',
            );
        }

        if ($response->status() === 404) {
            return AnalyticsStatsRead::failed(
                $siteDomain,
                'analytics.stats_site_missing',
                'Plausible has no site for this App instance domain.',
            );
        }

        return AnalyticsStatsRead::failed(
            $siteDomain,
            'analytics.stats_unreadable',
            'Plausible did not answer the Stats API.',
        );
    }

    private function liveVisitors(Response $response): ?int
    {
        $body = $response->json();

        if (is_int($body) || is_float($body)) {
            return $this->count($body);
        }

        if (is_array($body)) {
            foreach (['visitors', 'realtime_visitors'] as $key) {
                $count = $this->count($body[$key] ?? null);

                if ($count !== null) {
                    return $count;
                }
            }
        }

        return $this->count($response->body());
    }

    private function aggregateVisitors(Response $response): ?int
    {
        $value = $response->json('results.visitors.value');

        return $this->count($value);
    }

    /** @return list<AnalyticsPageStat>|null */
    private function pages(Response $response): ?array
    {
        $rows = $response->json('results');

        if (! is_array($rows)) {
            return null;
        }

        $pages = [];

        foreach (array_slice($rows, 0, self::PageLimit) as $row) {
            if (! is_array($row)) {
                return null;
            }

            $path = $row['page'] ?? $row['event:page'] ?? null;
            $visitors = $this->count($row['visitors'] ?? null);

            if (! is_string($path) || $path === '' || $visitors === null) {
                return null;
            }

            $pages[] = new AnalyticsPageStat(mb_substr($path, 0, 500), $visitors);
        }

        return $pages;
    }

    private function count(mixed $value): ?int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        if (is_float($value) || (is_string($value) && is_numeric($value))) {
            return max(0, (int) $value);
        }

        return null;
    }
}
