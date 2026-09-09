<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Exceptions\GatewayConfigException;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->configDirectory = sys_get_temp_dir().'/orbit-cli-'.Str::uuid();
    $this->configPath = $this->configDirectory.'/config.json';
});

afterEach(function (): void {
    new Filesystem()->deleteDirectory($this->configDirectory);
});

describe(GatewayConfigRepository::class, function (): void {
    it('serializes an empty gateway map as a JSON object', function (): void {
        $repository = new GatewayConfigRepository($this->configPath);
        $write = new ReflectionMethod($repository, 'write');

        $write->invoke($repository, [
            'active_gateway' => null,
            'gateways' => [],
        ]);

        expect(file_get_contents($this->configPath))
            ->toContain('"gateways": {}');
    });

    it('serializes numeric gateway maps as objects and round trips every profile', function (
        array $names,
        string $active,
    ): void {
        $repository = new GatewayConfigRepository($this->configPath);

        foreach ($names as $index => $name) {
            $repository->add(new GatewayProfile($name, 'https://10.70.0.'.($index + 1)));
        }

        $repository->use($active);

        expect(file_get_contents($this->configPath))
            ->toContain('"gateways": {');

        foreach ($names as $index => $name) {
            expect($repository->find($name)?->url)->toBe('https://10.70.0.'.($index + 1));
        }

        expect($repository->active()?->name)->toBe($active);
    })->with([
        'sole numeric name' => [['0'], '0'],
        'sequential numeric names' => [['0', '1'], '1'],
        'mixed numeric and word names' => [['0', 'production'], 'production'],
    ]);

    it('persists gateway profiles and activates the first profile', function (): void {
        expect(class_exists(GatewayConfigRepository::class))->toBeTrue();

        $repository = new GatewayConfigRepository($this->configPath);
        $repository->add(new GatewayProfile(
            name: 'test',
            url: 'https://10.70.0.1',
            caPath: '/home/orbit/.orbit/ca/root.pem',
        ));

        $reloaded = new GatewayConfigRepository($this->configPath);

        expect($reloaded->active())
            ->toEqual(new GatewayProfile(
                name: 'test',
                url: 'https://10.70.0.1',
                caPath: '/home/orbit/.orbit/ca/root.pem',
            ))
            ->and(fileperms($this->configPath) & 0o777)
            ->toBe(0o600)
            ->and(is_file($this->configPath.'.lock'))
            ->toBeTrue()
            ->and(fileperms($this->configPath.'.lock') & 0o777)
            ->toBe(0o600);
    });

    it('switches the active profile without changing other profiles', function (): void {
        expect(class_exists(GatewayConfigRepository::class))->toBeTrue();

        $repository = new GatewayConfigRepository($this->configPath);
        $repository->add(new GatewayProfile('test', 'https://10.70.0.1'));
        $repository->add(new GatewayProfile('production', 'https://10.80.0.1'));
        $repository->use('production');

        expect($repository->active()?->name)
            ->toBe('production')
            ->and($repository->find('test')?->url)
            ->toBe('https://10.70.0.1');
    });

    it('rejects an unknown active profile', function (): void {
        expect(class_exists(GatewayConfigException::class))->toBeTrue();

        $repository = new GatewayConfigRepository($this->configPath);

        expect(fn () => $repository->use('missing'))
            ->toThrow(GatewayConfigException::class, 'Gateway profile [missing] does not exist.');
    });
});

it('preserves a same-name profile replacement when a stale pin update finishes later', function (
    GatewayProfile $replacement,
): void {
    $repository = new GatewayConfigRepository($this->configPath);
    $expected = new GatewayProfile(
        name: 'test',
        url: 'https://10.70.0.1',
        caPath: '/home/orbit/.orbit/ca/old.pem',
    );
    $repository->add($expected);
    $repository->add($replacement);

    expect(fn () => $repository->updatePin($expected, '/home/orbit/.orbit/ca/fetched.pem'))
        ->toThrow(
            GatewayConfigException::class,
            'Gateway profile changed while its root CA was being trusted.',
        )
        ->and($repository->find('test'))
        ->toEqual($replacement);
})->with([
    'URL replacement' => new GatewayProfile(
        name: 'test',
        url: 'https://10.80.0.1',
        caPath: '/home/orbit/.orbit/ca/old.pem',
    ),
    'pin replacement' => new GatewayProfile(
        name: 'test',
        url: 'https://10.70.0.1',
        caPath: '/home/orbit/.orbit/ca/competing.pem',
    ),
]);

it('updates a pin without reverting an independent active-profile switch', function (): void {
    $repository = new GatewayConfigRepository($this->configPath);
    $expected = new GatewayProfile('test', 'https://10.70.0.1');
    $repository->add($expected);
    $repository->add(new GatewayProfile('production', 'https://10.80.0.1'));
    $repository->use('production');

    $repository->updatePin($expected, '/home/orbit/.orbit/ca/fetched.pem');

    expect($repository->active()?->name)
        ->toBe('production')
        ->and($repository->find('test'))
        ->toEqual(new GatewayProfile(
            name: 'test',
            url: 'https://10.70.0.1',
            caPath: '/home/orbit/.orbit/ca/fetched.pem',
        ));
});

it('accepts an identical pin update that completed before the stale updater', function (): void {
    $repository = new GatewayConfigRepository($this->configPath);
    $expected = new GatewayProfile('test', 'https://10.70.0.1');
    $updated = new GatewayProfile(
        name: 'test',
        url: 'https://10.70.0.1',
        caPath: '/home/orbit/.orbit/ca/fetched.pem',
    );
    $repository->add($expected);
    $repository->add($updated);

    $repository->updatePin($expected, '/home/orbit/.orbit/ca/fetched.pem');

    expect($repository->find('test'))->toEqual($updated);
});

it('preserves concurrent profile additions on first use', function (): void {
    $operations = [];

    for ($index = 0; $index < 8; $index++) {
        $operations[] = [
            'type' => 'add',
            'name' => "gateway-{$index}",
            'url' => "https://10.70.0.{$index}",
        ];
    }

    gateway_config_run_operations($this->configPath, $this->configDirectory, $operations);

    $repository = new GatewayConfigRepository($this->configPath);

    foreach ($operations as $operation) {
        expect($repository->find($operation['name'])?->url)->toBe($operation['url']);
    }

    expect($repository->active()?->name)->toBeIn(array_column($operations, 'name'));
});

it('serializes concurrent add and use operations around a fresh read', function (): void {
    $repository = new GatewayConfigRepository($this->configPath);
    $repository->add(new GatewayProfile('test', 'https://10.70.0.1'));
    $repository->add(new GatewayProfile('production', 'https://10.80.0.1'));
    $lock = gateway_config_test_lock($this->configPath);

    gateway_config_run_operations(
        $this->configPath,
        $this->configDirectory,
        [
            ['type' => 'add', 'name' => 'staging', 'url' => 'https://10.90.0.1'],
            ['type' => 'use', 'name' => 'production'],
        ],
        $lock,
    );

    expect($repository->find('staging')?->url)
        ->toBe('https://10.90.0.1')
        ->and($repository->active()?->name)
        ->toBe('production');
});

it('times out after five seconds without an unlocked write', function (): void {
    $repository = new GatewayConfigRepository($this->configPath);
    $repository->add(new GatewayProfile('test', 'https://10.70.0.1'));
    $original = file_get_contents($this->configPath);
    $lock = gateway_config_test_lock($this->configPath);
    $exception = null;
    $startedAt = hrtime(true);

    try {
        $repository->add(new GatewayProfile('waiting', 'https://10.80.0.1'));
    } catch (GatewayConfigException $caught) {
        $exception = $caught;
    } finally {
        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    expect($exception?->getMessage())
        ->toBe('Timed out after 5 seconds waiting to update Orbit gateway configuration.')
        ->and($elapsedSeconds)
        ->toBeGreaterThanOrEqual(4.9)
        ->toBeLessThan(7.0)
        ->and(file_get_contents($this->configPath))
        ->toBe($original)
        ->and($repository->find('waiting'))
        ->toBeNull();
});

it('releases the stable lock when a mutation fails', function (): void {
    $repository = new GatewayConfigRepository($this->configPath);

    expect(fn () => $repository->use('missing'))
        ->toThrow(GatewayConfigException::class, 'Gateway profile [missing] does not exist.');

    $lock = gateway_config_test_lock($this->configPath);
    flock($lock, LOCK_UN);
    fclose($lock);

    $repository->add(new GatewayProfile('test', 'https://10.70.0.1'));

    expect($repository->active()?->name)
        ->toBe('test')
        ->and(is_file($this->configPath.'.lock'))
        ->toBeTrue();
});

/**
 * @param list<array{type: 'add'|'use', name: string, url?: string}> $operations
 * @param resource|null $heldLock
 */
function gateway_config_run_operations(
    string $path,
    string $directory,
    array $operations,
    $heldLock = null,
): void {
    $markerDirectory = $directory.'/operations-'.Str::uuid();
    mkdir(directory: $markerDirectory, permissions: 0o700, recursive: true);
    $startPath = $markerDirectory.'/start';
    $processes = [];
    $lockHeld = is_resource($heldLock);

    try {
        foreach ($operations as $index => $operation) {
            $process = gateway_config_operation_process(
                $path,
                $operation,
                [
                    'ready' => "{$markerDirectory}/ready-{$index}",
                    'start' => $startPath,
                    'attempted' => "{$markerDirectory}/attempted-{$index}",
                ],
            );
            $process->start();
            $processes[] = $process;
        }

        gateway_config_wait_until(
            static fn (): bool => gateway_config_marker_count($markerDirectory.'/ready-*') === count($operations),
        );
        file_put_contents(filename: $startPath, data: 'start');
        gateway_config_wait_until(
            static fn (): bool => gateway_config_marker_count($markerDirectory.'/attempted-*') === count($operations),
        );

        if ($lockHeld) {
            usleep(50_000);

            foreach ($processes as $process) {
                expect($process->isRunning())->toBeTrue();
            }

            flock($heldLock, LOCK_UN);
            $lockHeld = false;
        }

        foreach ($processes as $process) {
            gateway_config_wait_for_success($process);
        }
    } finally {
        if ($lockHeld) {
            flock($heldLock, LOCK_UN);
        }

        if (is_resource($heldLock)) {
            fclose($heldLock);
        }

        foreach ($processes as $process) {
            gateway_config_stop_process($process);
        }
    }
}

/**
 * @param array{type: 'add'|'use', name: string, url?: string} $operation
 * @param array{ready: string, start: string, attempted: string} $markers
 */
function gateway_config_operation_process(string $path, array $operation, array $markers): Process
{
    $code = <<<'PHP'
        require $argv[1];

        file_put_contents($argv[5], 'ready');

        while (! is_file($argv[6])) {
            usleep(1_000);
        }

        file_put_contents($argv[7], 'attempted');

        try {
            $operation = json_decode($argv[3], true, flags: JSON_THROW_ON_ERROR);
            $repository = new App\Repositories\GatewayConfigRepository($argv[2]);

            if ($operation['type'] === 'add') {
                $repository->add(new App\Data\GatewayProfile(
                    $operation['name'],
                    $operation['url'],
                ));
            } else {
                $repository->use($operation['name']);
            }
        } catch (Throwable $exception) {
            fwrite(STDERR, $exception::class.': '.$exception->getMessage());
            exit(1);
        }
        PHP;

    return new Process([
        PHP_BINARY,
        '-r',
        $code,
        dirname(path: __DIR__, levels: 2).'/vendor/autoload.php',
        $path,
        json_encode($operation, JSON_THROW_ON_ERROR),
        $operation['name'],
        $markers['ready'],
        $markers['start'],
        $markers['attempted'],
    ], timeout: 15);
}

/** @return resource */
function gateway_config_test_lock(string $path)
{
    $lock = fopen(filename: $path.'.lock', mode: 'rb');

    if ($lock === false || ! flock($lock, LOCK_EX)) {
        throw new RuntimeException('Could not acquire the gateway-config test lock.');
    }

    return $lock;
}

function gateway_config_marker_count(string $pattern): int
{
    $markers = glob($pattern);

    return is_array($markers) ? count($markers) : 0;
}

function gateway_config_wait_for_success(Process $process): void
{
    $exitCode = $process->wait();

    if ($exitCode !== 0) {
        throw new RuntimeException(
            "Gateway-config operation failed with exit code {$exitCode}: {$process->getErrorOutput()}",
        );
    }
}

function gateway_config_stop_process(Process $process): void
{
    if (! $process->isRunning()) {
        return;
    }

    $process->stop(timeout: 0.1, signal: 9);
}

function gateway_config_wait_until(Closure $condition, float $timeoutSeconds = 5.0): void
{
    $deadline = hrtime(true) + (int) ($timeoutSeconds * 1_000_000_000);

    while (! $condition()) {
        if (hrtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for the gateway-config test condition.');
        }

        usleep(1_000);
    }
}
