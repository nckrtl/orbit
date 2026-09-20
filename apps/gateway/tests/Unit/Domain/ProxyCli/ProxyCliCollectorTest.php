<?php

declare(strict_types=1);

use App\Domain\ProxyCli\ProxyCliCollector;
use App\Domain\ProxyCli\ProxyCliKeys;
use App\Domain\ProxyCli\ProxyCliManagementClient;
use App\Domain\ProxyCli\ProxyCliSnapshotStore;
use App\Domain\ProxyCli\ProxyCliUsageResponse;
use App\Infrastructure\ProxyCli\ArrayProxyCliCache;

it('skips a second collect while the Valkey lock is held', function (): void {
    $cache = new ArrayProxyCliCache;
    $client = new class implements ProxyCliManagementClient
    {
        public int $quotaCalls = 0;

        public function authFiles(string $baseUrl, string $managementKey): array
        {
            return [[
                'name' => 'plus.json',
                'provider' => 'codex',
                'disabled' => false,
                'status' => 'enabled',
            ]];
        }

        public function apiCall(string $baseUrl, string $managementKey, string $authIndex, string $url, array $headers = []): ProxyCliUsageResponse
        {
            $this->quotaCalls++;

            return new ProxyCliUsageResponse(200, [
                'rate_limit' => [
                    'primary_window' => ['used_percent' => 10, 'reset_at' => '2026-09-27T00:00:00Z'],
                ],
            ]);
        }

        public function setDisabled(string $baseUrl, string $managementKey, string $account, bool $disabled): void {}
    };
    $store = new ProxyCliSnapshotStore($cache);
    $collector = new ProxyCliCollector($client, $store, $cache, holder: 'collector-a');

    $collector->collect('http://127.0.0.1:8317', 'key');
    expect($client->quotaCalls)->toBe(1);

    $cache->acquire(ProxyCliKeys::Lock, 'collector-b', 30);
    $held = new ProxyCliCollector($client, $store, $cache, holder: 'collector-a');
    $held->collect('http://127.0.0.1:8317', 'key');

    expect($client->quotaCalls)->toBe(1);
});

it('honors Retry-After and skips a backed-off account on the next collect', function (): void {
    $cache = new ArrayProxyCliCache;
    $client = new class implements ProxyCliManagementClient
    {
        public int $quotaCalls = 0;

        public function authFiles(string $baseUrl, string $managementKey): array
        {
            return [[
                'name' => 'plus.json',
                'provider' => 'codex',
                'disabled' => false,
                'status' => 'enabled',
            ]];
        }

        public function apiCall(string $baseUrl, string $managementKey, string $authIndex, string $url, array $headers = []): ProxyCliUsageResponse
        {
            $this->quotaCalls++;

            return new ProxyCliUsageResponse(429, [], retryAfterSeconds: 60);
        }

        public function setDisabled(string $baseUrl, string $managementKey, string $account, bool $disabled): void {}
    };
    $store = new ProxyCliSnapshotStore($cache);
    $collector = new ProxyCliCollector($client, $store, $cache, holder: 'collector-a');

    $first = $collector->collect('http://127.0.0.1:8317', 'key');
    $second = $collector->collect('http://127.0.0.1:8317', 'key');

    expect($first[0]->status)->toBe('backoff')
        ->and($second[0]->status)->toBe('backoff')
        ->and($client->quotaCalls)->toBe(1)
        ->and($store->backoffUntil('plus.json'))->toBeInt();
});
