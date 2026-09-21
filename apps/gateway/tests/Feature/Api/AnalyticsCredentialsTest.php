<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Analytics\NativeAnalyticsStatsKeyStore;
use App\Models\Activity;
use App\Models\Node;

beforeEach(function (): void {
    $this->gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $this->markAsGateway($this->gateway);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.1']);
    $this->secret = 'plausible-stats-sentinel-key';
});

describe('analytics:credentials', function (): void {
    it('reports that no Stats API key is stored', function (): void {
        $this->getJson('/api/v1/analytics/credentials')
            ->assertOk()
            ->assertJsonPath('data', [
                'configured' => false,
                'driver' => 'plausible_ce',
            ]);
    });

    it('stores a key and never returns it', function (): void {
        $response = $this->putJson('/api/v1/analytics/credentials', ['api_key' => $this->secret])
            ->assertOk();

        expect($response->json('data'))->toBe([
            'configured' => true,
            'driver' => 'plausible_ce',
        ])
            ->and($response->getContent())->not->toContain($this->secret)
            ->and(app(NativeAnalyticsStatsKeyStore::class)->get())->toBe($this->secret);

        $this->getJson('/api/v1/analytics/credentials')
            ->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonMissing(['api_key' => $this->secret]);
    });

    it('clears the stored key', function (): void {
        $this->putJson('/api/v1/analytics/credentials', ['api_key' => $this->secret])->assertOk();

        $this->deleteJson('/api/v1/analytics/credentials')
            ->assertOk()
            ->assertJsonPath('data.configured', false);

        expect(app(NativeAnalyticsStatsKeyStore::class)->get())->toBeNull();
    });

    it('refuses an empty key', function (): void {
        $this->putJson('/api/v1/analytics/credentials', ['api_key' => ''])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed');
    });

    it('redacts the key from activity input', function (): void {
        $this->putJson('/api/v1/analytics/credentials', ['api_key' => $this->secret])->assertOk();

        $activity = Activity::query()->where('command', 'analytics:credentials:set')->latest('id')->first();

        expect($activity)->not->toBeNull()
            ->and(json_encode($activity?->properties))->not->toContain($this->secret);
    });
});
