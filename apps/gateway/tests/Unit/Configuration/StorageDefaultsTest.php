<?php

declare(strict_types=1);

use App\Domain\Gateway\GatewayCacheStore;
use Tests\TestCase;

uses(TestCase::class);

describe('Gateway storage defaults', function (): void {
    it('selects stores that need no database tables when the env leaves them unset', function (): void {
        expect(config_without_env('cache.php', ['CACHE_STORE'])['default'])->toBe('file')
            ->and(config_without_env('session.php', ['SESSION_DRIVER'])['driver'])->toBe('array')
            ->and(config_without_env('queue.php', ['QUEUE_CONNECTION'])['default'])->toBe('sync')
            ->and(config_without_env('app.php', ['APP_MAINTENANCE_STORE'])['maintenance']['store'])->toBe('file');
    });

    it('accepts the default cache store', function (): void {
        GatewayCacheStore::assertSupported(config_without_env('cache.php', ['CACHE_STORE']));
    })->throwsNoExceptions();

    it('refuses a cache store that cannot hold Gateway locks', function (string $driver): void {
        GatewayCacheStore::assertSupported([
            'default' => 'locks',
            'stores' => ['locks' => ['driver' => $driver]],
        ]);
    })->with(['database', 'null'])->throws(RuntimeException::class, 'cannot hold Gateway locks');

    it('refuses an unknown cache store', function (): void {
        GatewayCacheStore::assertSupported([...config('cache'), 'default' => 'missing']);
    })->throws(RuntimeException::class, 'The Gateway cache store [missing] is not configured.');
});
