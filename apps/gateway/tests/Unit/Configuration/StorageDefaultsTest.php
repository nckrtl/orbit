<?php

declare(strict_types=1);

use App\Domain\Gateway\GatewayCacheStore;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

describe('Gateway storage defaults', function (): void {
    it('selects stores that need no database tables when the env leaves them unset', function (): void {
        expect(config_without_env('cache.php', ['CACHE_STORE'])['default'])->toBe('file')
            ->and(config_without_env('session.php', ['SESSION_DRIVER'])['driver'])->toBe('array')
            ->and(config_without_env('queue.php', ['QUEUE_CONNECTION'])['default'])->toBe('sync')
            ->and(config_without_env('app.php', ['APP_MAINTENANCE_STORE'])['maintenance']['store'])->toBe('file');
    });

    it('accepts the default cache store in production', function (): void {
        GatewayCacheStore::assertSupported(config_without_env('cache.php', ['CACHE_STORE']), 'production');
    })->throwsNoExceptions();

    it('accepts the array store only in the testing environment', function (): void {
        GatewayCacheStore::assertSupported(['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]], 'testing');
    })->throwsNoExceptions();

    it('refuses a cache store whose locks do not span processes', function (string $driver): void {
        GatewayCacheStore::assertSupported([
            'default' => 'locks',
            'stores' => ['locks' => ['driver' => $driver]],
        ], 'production');
    })->with(['database', 'null', 'array'])->throws(RuntimeException::class, 'cannot hold Gateway locks across processes. Set CACHE_STORE=file');

    it('refuses an unknown cache store', function (): void {
        GatewayCacheStore::assertSupported([...config('cache'), 'default' => 'missing'], 'production');
    })->throws(RuntimeException::class, 'The Gateway cache store [missing] is not configured.');

    it('tells the operator to clear a cached configuration', function (): void {
        GatewayCacheStore::assertSupported(['default' => 'database', 'stores' => ['database' => ['driver' => 'database']]], 'production', configurationIsCached: true);
    })->throws(RuntimeException::class, 'The configuration is cached: run php artisan config:clear (or delete bootstrap/cache/config.php).');
});

describe('a stale cached configuration with a database cache store', function (): void {
    /** @return array{0: string, 1: array<string, string>} */
    function stale_cached_config(): array
    {
        $directory = sys_get_temp_dir().'/orbit-gateway-cache-store-'.bin2hex(random_bytes(8));
        mkdir($directory, 0o700, true);
        $path = $directory.'/config.php';
        $configuration = config()->all();
        $configuration['cache']['default'] = 'database';
        $configuration['app']['env'] = 'production';
        file_put_contents($path, '<?php return '.var_export($configuration, true).';'.PHP_EOL);

        return [$path, ['APP_CONFIG_CACHE' => $path, 'APP_ENV' => 'production']];
    }

    it('refuses other commands and names config:clear', function (): void {
        [$path, $environment] = stale_cached_config();

        $process = new Process([PHP_BINARY, 'artisan', 'about', '--only=environment'], base_path(), $environment);
        $process->run();

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getOutput().$process->getErrorOutput())
            ->toContain('cannot hold Gateway locks across processes')
            ->toContain('The configuration is cached: run php artisan config:clear');
        unlink($path);
    });

    it('lets config:clear remove the stale cache and package:discover run', function (string $command): void {
        [$path, $environment] = stale_cached_config();

        $process = new Process([PHP_BINARY, 'artisan', $command, '--no-interaction'], base_path(), $environment);
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput());
        if ($command === 'config:clear') {
            expect(is_file($path))->toBeFalse();
        }
        @unlink($path);
    })->with(['config:clear', 'package:discover']);

    it('lets optimize:clear past the guard to clear the cached configuration', function (): void {
        [$path, $environment] = stale_cached_config();

        $process = new Process([PHP_BINARY, 'artisan', 'optimize:clear', '--no-interaction'], base_path(), $environment);
        $process->run();

        expect($process->getOutput().$process->getErrorOutput())->not->toContain('cannot hold Gateway locks')
            ->and(is_file($path))->toBeFalse();
        @unlink($path);
    });
});
