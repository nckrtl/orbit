<?php

declare(strict_types=1);

use App\Domain\Analytics\AnalyticsStorageConnection;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Process;

beforeEach(function (): void {
    $this->node = Node::query()->create([
        'name' => 'services',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.30',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.3',
    ]);
    $this->postgres = fn (array $environment = ['POSTGRES_PASSWORD' => 'p@ss/word'], array $ports = ['10.44.0.3:5432:5432/tcp']): Process => analytics_connection_process($this->node, 'analytics-postgres', 'postgres:16-alpine', $environment, $ports);
    $this->clickhouse = fn (array $environment = ['CLICKHOUSE_USER' => 'plausible', 'CLICKHOUSE_PASSWORD' => 'click house', 'CLICKHOUSE_DB' => 'plausible_events_db'], array $ports = ['10.44.0.3:8123:8123/tcp']): Process => analytics_connection_process($this->node, 'analytics-clickhouse', 'clickhouse/clickhouse-server:24.12-alpine', $environment, $ports);
});

describe('AnalyticsStorageConnection', function (): void {
    it('derives both URLs from what the two Processes declare, over WireGuard', function (): void {
        $connection = AnalyticsStorageConnection::from(($this->postgres)(), ($this->clickhouse)());

        expect($connection->databaseUrl)->toBe('postgres://postgres:p%40ss%2Fword@10.44.0.3:5432/plausible_db')
            ->and($connection->clickhouseDatabaseUrl)->toBe('http://plausible:click%20house@10.44.0.3:8123/plausible_events_db');
    });

    it('uses the declared PostgreSQL user and the published port, not the container port', function (): void {
        $connection = AnalyticsStorageConnection::from(
            ($this->postgres)(['POSTGRES_USER' => 'analytics', 'POSTGRES_PASSWORD' => 'secret'], ['10.44.0.3:15432:5432/tcp']),
            ($this->clickhouse)(),
        );

        expect($connection->databaseUrl)->toBe('postgres://analytics:secret@10.44.0.3:15432/plausible_db');
    });

    it('refuses a Process that lacks a credential or does not publish its port', function (Closure $postgres, Closure $clickhouse, string $code): void {
        expect(fn () => AnalyticsStorageConnection::from($postgres->call($this), $clickhouse->call($this)))
            ->toThrow(function (ResourceOperationException $exception) use ($code): void {
                expect($exception->errorCode)->toBe($code)->and($exception->status)->toBe(422);
            });
    })->with([
        'no PostgreSQL password' => [fn () => ($this->postgres)([]), fn () => ($this->clickhouse)(), 'analytics.storage_credentials_missing'],
        'no ClickHouse database' => [fn () => ($this->postgres)(), fn () => ($this->clickhouse)(['CLICKHOUSE_USER' => 'plausible', 'CLICKHOUSE_PASSWORD' => 'x']), 'analytics.storage_credentials_missing'],
        'PostgreSQL port not published' => [fn () => ($this->postgres)(['POSTGRES_PASSWORD' => 'x'], []), fn () => ($this->clickhouse)(), 'analytics.storage_port_missing'],
        'ClickHouse publishes another port' => [fn () => ($this->postgres)(), fn () => ($this->clickhouse)(['CLICKHOUSE_USER' => 'u', 'CLICKHOUSE_PASSWORD' => 'p', 'CLICKHOUSE_DB' => 'd'], ['10.44.0.3:9000:9000/tcp']), 'analytics.storage_port_missing'],
    ]);

    it('never prints a URL when it is dumped', function (): void {
        $connection = AnalyticsStorageConnection::from(($this->postgres)(), ($this->clickhouse)());

        expect(print_r($connection, true))->not->toContain('word')->not->toContain('click');
    });
});
