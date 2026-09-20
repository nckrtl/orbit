<?php

declare(strict_types=1);

use App\Domain\Analytics\AnalyticsStorageProcessGuard;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\App as OrbitApp;

describe(AnalyticsStorageProcessGuard::class, function (): void {
    it('returns the two Processes when both are supported servers on an active database Node', function (): void {
        $storage = analytics_storage_processes();

        $processes = new AnalyticsStorageProcessGuard()->assert($storage['postgres']->id, $storage['clickhouse']->id);

        expect($processes['postgres']->is($storage['postgres']))
            ->toBeTrue()
            ->and($processes['clickhouse']->is($storage['clickhouse']))
            ->toBeTrue();
    });

    it('accepts PostgreSQL 16 and ClickHouse images', function (string $postgres, string $clickhouse): void {
        $node = analytics_database_node();
        $postgresProcess = analytics_storage_process($node, 'pg', $postgres);
        $clickhouseProcess = analytics_storage_process($node, 'ch', $clickhouse);

        $processes = new AnalyticsStorageProcessGuard()->assert($postgresProcess->id, $clickhouseProcess->id);

        expect($processes)->toHaveKeys(['postgres', 'clickhouse']);
    })->with([
        'documented tags' => ['postgres:16-alpine', 'clickhouse/clickhouse-server:24.12-alpine'],
        'bare major and no tag' => ['postgres:16', 'clickhouse/clickhouse-server'],
        'minor and registry path' => ['docker.io/library/postgres:16.4', 'docker.io/clickhouse/clickhouse-server:24.12'],
        'digest pins' => ['postgres:16.4-bookworm@sha256:abc', 'clickhouse/clickhouse-server@sha256:abc'],
    ]);

    it('refuses a missing Process', function (bool $postgresMissing, string $errorCode): void {
        $storage = analytics_storage_processes();
        $missing = $storage['clickhouse']->id + 100;

        expect(fn () => new AnalyticsStorageProcessGuard()->assert(
            $postgresMissing ? $missing : $storage['postgres']->id,
            $postgresMissing ? $storage['clickhouse']->id : $missing,
        ))->toThrow(
            fn (ResourceOperationException $exception) => expect($exception->errorCode)
                ->toBe($errorCode)
                ->and($exception->status)
                ->toBe(422),
        );
    })->with([
        'PostgreSQL' => [true, 'analytics.postgres_process_missing'],
        'ClickHouse' => [false, 'analytics.clickhouse_process_missing'],
    ]);

    it('refuses a Process that no Node owns', function (): void {
        $storage = analytics_storage_processes();
        $app = OrbitApp::query()->create(['name' => 'shop', 'slug' => 'shop', 'repository_url' => 'git@example.test:shop.git']);
        $storage['postgres']->update(['owner_type' => OrbitApp::class, 'owner_id' => $app->id]);

        expect(fn () => new AnalyticsStorageProcessGuard()->assert($storage['postgres']->id, $storage['clickhouse']->id))
            ->toThrow(
                fn (ResourceOperationException $exception) => expect($exception->errorCode)
                    ->toBe('analytics.process_not_node')
                    ->and($exception->status)
                    ->toBe(422),
            );
    });

    it('refuses a Process whose owning Node no longer exists', function (): void {
        $storage = analytics_storage_processes();
        $storage['clickhouse']->update(['owner_id' => $storage['node']->id + 100]);

        expect(fn () => new AnalyticsStorageProcessGuard()->assert($storage['postgres']->id, $storage['clickhouse']->id))
            ->toThrow(
                fn (ResourceOperationException $exception) => expect($exception->errorCode)
                    ->toBe('analytics.process_not_node'),
            );
    });

    it('refuses a Process that Docker does not run', function (): void {
        $storage = analytics_storage_processes();
        $storage['clickhouse']->update(['runtime' => ProcessRuntime::Systemd]);

        expect(fn () => new AnalyticsStorageProcessGuard()->assert($storage['postgres']->id, $storage['clickhouse']->id))
            ->toThrow(
                fn (ResourceOperationException $exception) => expect($exception->errorCode)
                    ->toBe('analytics.process_not_docker')
                    ->and($exception->status)
                    ->toBe(422),
            );
    });

    it('refuses a Process whose Node is not an active database Node', function (Closure $arrange): void {
        $storage = analytics_storage_processes();
        $arrange($storage['node']);

        expect(fn () => new AnalyticsStorageProcessGuard()->assert($storage['postgres']->id, $storage['clickhouse']->id))
            ->toThrow(
                fn (ResourceOperationException $exception) => expect($exception->errorCode)
                    ->toBe('analytics.process_not_database_node')
                    ->and($exception->status)
                    ->toBe(422),
            );
    })->with([
        'the Node is not active' => [fn ($node) => $node->update(['status' => LifecycleStatus::Failed])],
        'the Node has no database role' => [fn ($node) => $node->roles()->delete()],
        'the database role is not active' => [
            fn ($node) => $node->roles()->where('role', RoleName::Database)->update(['status' => LifecycleStatus::Failed]),
        ],
    ]);

    it('refuses a PostgreSQL Process that is not major 16', function (string $image): void {
        $storage = analytics_storage_processes();
        $storage['postgres']->update(['runtime_config' => ['image' => $image]]);

        expect(fn () => new AnalyticsStorageProcessGuard()->assert($storage['postgres']->id, $storage['clickhouse']->id))
            ->toThrow(
                fn (ResourceOperationException $exception) => expect($exception->errorCode)
                    ->toBe('analytics.postgres_unsupported')
                    ->and($exception->status)
                    ->toBe(422),
            );
    })->with([
        'another major' => ['postgres:17-alpine'],
        'a major that starts with 16' => ['postgres:160'],
        'no tag' => ['postgres'],
        'latest' => ['postgres:latest'],
        'another engine' => ['mysql:8.4'],
        'a look-alike repository' => ['example/notpostgres:16'],
    ]);

    it('refuses a ClickHouse Process that runs another image', function (string $image): void {
        $storage = analytics_storage_processes();
        $storage['clickhouse']->update(['runtime_config' => ['image' => $image]]);

        expect(fn () => new AnalyticsStorageProcessGuard()->assert($storage['postgres']->id, $storage['clickhouse']->id))
            ->toThrow(
                fn (ResourceOperationException $exception) => expect($exception->errorCode)
                    ->toBe('analytics.clickhouse_unsupported')
                    ->and($exception->status)
                    ->toBe(422),
            );
    })->with([
        'another engine' => ['postgres:16-alpine'],
        'another ClickHouse image' => ['clickhouse/clickhouse-keeper:24.12'],
        'a look-alike repository' => ['clickhouse/clickhouse-server-extra:24.12'],
    ]);
});
