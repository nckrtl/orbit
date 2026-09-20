<?php

declare(strict_types=1);

use App\Domain\Analytics\AnalyticsPageStat;
use App\Domain\Analytics\AnalyticsStatsKeyStore;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Analytics\PlausibleCommunityEditionStatsDriver;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->keys = new class implements AnalyticsStatsKeyStore
    {
        public ?string $key = 'plausible-stats-sentinel-key';

        public function get(): ?string
        {
            return $this->key;
        }

        public function put(#[SensitiveParameter] string $key): void
        {
            $this->key = $key;
        }

        public function clear(): void
        {
            $this->key = null;
        }

        public function configured(): bool
        {
            return $this->key !== null;
        }
    };

    $this->driver = new PlausibleCommunityEditionStatsDriver($this->keys);
    $this->base = 'http://10.44.0.40:8000';
});

function plausible_analytics_node(): Node
{
    $node = Node::query()->create([
        'name' => 'services',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.40',
        'wireguard_ip' => '10.44.0.40',
    ]);
    $node->roles()->create([
        'role' => RoleName::Analytics,
        'status' => LifecycleStatus::Active,
    ]);

    return $node;
}

function plausible_stats_ok(): array
{
    $aggregate = static fn (int $visitors): array => [
        'results' => ['visitors' => ['value' => $visitors]],
    ];

    return [
        'http://10.44.0.40:8000/api/v1/stats/realtime/visitors*' => Http::response('3'),
        'http://10.44.0.40:8000/api/v1/stats/aggregate*period=day*' => Http::response($aggregate(18)),
        'http://10.44.0.40:8000/api/v1/stats/aggregate*period=7d*' => Http::response($aggregate(91)),
        'http://10.44.0.40:8000/api/v1/stats/aggregate*period=30d*' => Http::response($aggregate(340)),
        'http://10.44.0.40:8000/api/v1/stats/breakdown*' => Http::response([
            'results' => [
                ['page' => '/', 'visitors' => 120],
                ['page' => '/pricing', 'visitors' => 40],
            ],
        ]),
    ];
}

describe(PlausibleCommunityEditionStatsDriver::class, function (): void {
    it('is unhealthy when no analytics role is active', function (): void {
        expect($this->driver->fleetHealthy())->toBeFalse();
    });

    it('is healthy when the analytics role has a WireGuard address', function (): void {
        plausible_analytics_node();

        expect($this->driver->fleetHealthy())->toBeTrue();
    });

    it('fails without inventing zeros when no key is stored', function (): void {
        plausible_analytics_node();
        $this->keys->key = null;

        $read = $this->driver->read('shop.example.com');

        expect($read->readable)->toBeFalse()
            ->and($read->errorCode)->toBe('analytics.stats_key_missing')
            ->and($read->liveVisitors)->toBeNull()
            ->and($read->visitors24h)->toBeNull()
            ->and($read->pages)->toBe([]);
    });

    it('reads live visitors, period counts, and top pages for the site domain', function (): void {
        plausible_analytics_node();
        Http::fake(plausible_stats_ok());

        $read = $this->driver->read('shop.example.com');

        expect($read->readable)->toBeTrue()
            ->and($read->siteDomain)->toBe('shop.example.com')
            ->and($read->liveVisitors)->toBe(3)
            ->and($read->visitors24h)->toBe(18)
            ->and($read->visitors7d)->toBe(91)
            ->and($read->visitors30d)->toBe(340)
            ->and($read->pages)->toHaveCount(2)
            ->and($read->pages[0])->toEqual(new AnalyticsPageStat('/', 120))
            ->and($read->pages[1])->toEqual(new AnalyticsPageStat('/pricing', 40));

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), 'site_id=shop.example.com')
                && $request->hasHeader('Authorization', 'Bearer plausible-stats-sentinel-key');
        });
    });

    it('treats an unauthorized key as unreadable, not as zeros', function (): void {
        plausible_analytics_node();
        Http::fake(['*' => Http::response(['error' => 'Invalid API key'], 401)]);

        $read = $this->driver->read('shop.example.com');

        expect($read->readable)->toBeFalse()
            ->and($read->errorCode)->toBe('analytics.stats_unauthorized')
            ->and($read->liveVisitors)->toBeNull();
    });

    it('treats a missing Plausible site as unreadable, not as zeros', function (): void {
        plausible_analytics_node();
        Http::fake(['*' => Http::response(['error' => 'Site does not exist'], 404)]);

        $read = $this->driver->read('shop.example.com');

        expect($read->readable)->toBeFalse()
            ->and($read->errorCode)->toBe('analytics.stats_site_missing')
            ->and($read->visitors30d)->toBeNull();
    });

    it('fails the whole read when one Stats API call does not answer', function (): void {
        plausible_analytics_node();
        $ok = plausible_stats_ok();
        $ok['http://10.44.0.40:8000/api/v1/stats/aggregate*period=30d*'] = Http::response('', 503);
        Http::fake($ok);

        $read = $this->driver->read('shop.example.com');

        expect($read->readable)->toBeFalse()
            ->and($read->errorCode)->toBe('analytics.stats_unreadable')
            ->and($read->liveVisitors)->toBeNull()
            ->and($read->visitors30d)->toBeNull();
    });
});
