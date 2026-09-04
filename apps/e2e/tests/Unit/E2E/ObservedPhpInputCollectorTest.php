<?php

declare(strict_types=1);

use App\E2E\GuestTransport;
use App\E2E\ObservedPhpInputCollector;
use App\E2E\Value\AttemptId;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\GuestCommandResult;
use App\E2E\Value\TopologyTarget;

function observedRecord(string $role, string $type, string $id, array $files): array
{
    return [
        'schema' => 2,
        'id' => $id,
        'attempt' => str_repeat('a', 32),
        'issue' => 'AUX-9',
        'phase' => 'setup',
        'role' => $role,
        'process_type' => $type,
        'pid' => 123,
        'php_version' => '8.5.10',
        'pcov_version' => '1.0.12',
        'started_at' => '2026-09-03T10:00:00.000001Z',
        'finished_at' => '2026-09-03T10:00:00.000002Z',
        'files' => $files,
    ];
}

function observedRuntimeInventory(string $phpVersion = '8.5.10', ?string $pcovVersion = '1.0.12'): array
{
    $packages = array_fill_keys([
        'php8.5-cli',
        'php8.5-fpm',
        'php8.5-common',
        'php8.5-curl',
        'php8.5-mbstring',
        'php8.5-sqlite3',
        'php8.5-xml',
    ], $phpVersion.'-sury');
    if ($pcovVersion !== null) {
        $packages['php8.5-pcov'] = $pcovVersion.'-sury';
    }

    return [
        'php_version' => $phpVersion,
        'fpm_version' => $phpVersion,
        'pcov_version' => $pcovVersion,
        'package_versions' => $packages,
    ];
}

function observedRuntimeTransport(array $inventories): GuestTransport
{
    return new class($inventories) implements GuestTransport {
        public function __construct(
            private array $inventories,
        ) {}

        public function exec(string $instance, GuestCommand $command): GuestCommandResult
        {
            return new GuestCommandResult('', '', 0);
        }

        public function execAll(array $commands): array
        {
            $results = [];
            foreach ($commands as $label => $request) {
                $role = str_contains($request['instance'], 'app-dev') ? 'app-dev' : 'gateway';
                $output = ($request['command']->command[1] ?? null) === 'runtime-info'
                    ? json_encode($this->inventories[$role], JSON_THROW_ON_ERROR)
                    : '';
                $results[$label] = new GuestCommandResult($output, '', 0);
            }

            return $results;
        }

        public function pushFile(string $instance, string $source, string $destination): void {}

        public function pushFiles(array $files): void {}
    };
}

function observedCollectedRuntimes(): array
{
    $inventory = observedRuntimeInventory();

    return [
        ['role' => 'app-dev', ...$inventory],
        ['role' => 'gateway', ...$inventory],
    ];
}

function observedTransport(array $roleRecords): GuestTransport
{
    return new class($roleRecords) implements GuestTransport {
        public function __construct(
            private array $roleRecords,
        ) {}

        public function exec(string $instance, GuestCommand $command): GuestCommandResult
        {
            return new GuestCommandResult('', '', 0);
        }

        public function execAll(array $commands): array
        {
            $results = [];
            foreach ($commands as $label => $request) {
                $role = str_contains($request['instance'], 'app-dev') ? 'app-dev' : 'gateway';
                $results[$label] = new GuestCommandResult(
                    json_encode($this->roleRecords[$role] ?? [], JSON_THROW_ON_ERROR),
                    '',
                    0,
                );
            }

            return $results;
        }

        public function pushFile(string $instance, string $source, string $destination): void {}

        public function pushFiles(array $files): void {}
    };
}

it('uses Incus-safe batch labels for every process-surface probe', function (): void {
    $transport = new class implements GuestTransport {
        public function exec(string $instance, GuestCommand $command): GuestCommandResult
        {
            return new GuestCommandResult('', '', 0);
        }

        public function execAll(array $commands): array
        {
            $results = [];
            foreach ($commands as $label => $request) {
                if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}\z/', (string) $label) !== 1) {
                    throw new RuntimeException('unsafe batch label');
                }
                $results[$label] = new GuestCommandResult('', '', 0);
            }

            return $results;
        }

        public function pushFile(string $instance, string $source, string $destination): void {}

        public function pushFiles(array $files): void {}
    };

    new ObservedPhpInputCollector($transport)->begin(
        TopologyTarget::feature('AUX-9', new AttemptId(str_repeat('a', 32))),
        'setup',
        'AUX-9',
        new AttemptId(str_repeat('a', 32)),
    );

    expect(true)->toBeTrue();
});

it('records one exact Sury CLI, FPM, and package inventory across both roles', function (): void {
    $inventory = observedRuntimeInventory();
    $collector = new ObservedPhpInputCollector(observedRuntimeTransport([
        'app-dev' => $inventory,
        'gateway' => $inventory,
    ]));

    expect($collector->prepare(
        TopologyTarget::feature('AUX-9', new AttemptId(str_repeat('a', 32))),
    ))->toBe([
        ['role' => 'app-dev', ...$inventory],
        ['role' => 'gateway', ...$inventory],
    ]);
});

it('requires identical Sury CLI and FPM packages before normal convergence', function (): void {
    $inventory = observedRuntimeInventory(pcovVersion: null);
    $collector = new ObservedPhpInputCollector(observedRuntimeTransport([
        'app-dev' => $inventory,
        'gateway' => $inventory,
    ]));

    $collector->normalizeRuntime(
        TopologyTarget::feature('AUX-9', new AttemptId(str_repeat('a', 32))),
    );

    expect(true)->toBeTrue();
});

it('fails before collection when Sury runtime inventories differ across roles', function (): void {
    $collector = new ObservedPhpInputCollector(observedRuntimeTransport([
        'app-dev' => observedRuntimeInventory(),
        'gateway' => observedRuntimeInventory('8.5.11'),
    ]));

    expect(fn () => $collector->prepare(
        TopologyTarget::feature('AUX-9', new AttemptId(str_repeat('a', 32))),
    ))
        ->toThrow(RuntimeException::class, 'runtime inventories differ');
});

it('unions concurrent CLI and FPM process paths by role without overwriting evidence', function (): void {
    $first = str_repeat('1', 32);
    $second = str_repeat('2', 32);
    $third = str_repeat('3', 32);
    $fourth = str_repeat('4', 32);
    $collector = new ObservedPhpInputCollector(observedTransport([
        'app-dev' => [
            observedRecord('app-dev', 'cli', $first, ['/home/orbit/orbit/apps/cli/app/One.php']),
        ],
        'gateway' => [
            observedRecord('gateway', 'cli', $second, ['/home/orbit/orbit/apps/cli/app/One.php']),
            observedRecord('gateway', 'cli', $third, ['/home/orbit/orbit/apps/cli/app/Two.php']),
            observedRecord('gateway', 'fpm', $fourth, ['/home/orbit/orbit/apps/gateway/app/Http.php']),
        ],
    ]));
    $entries = array_fill_keys([
        'apps/cli/app/One.php',
        'apps/cli/app/Two.php',
        'apps/gateway/app/Http.php',
    ], ['mode' => '100644', 'type' => 'blob', 'object' => str_repeat('b', 40)]);

    $surfaces = $collector->collect(
        TopologyTarget::feature('AUX-9', new AttemptId(str_repeat('a', 32))),
        'setup',
        'AUX-9',
        new AttemptId(str_repeat('a', 32)),
        observedCollectedRuntimes(),
        $entries,
    );

    expect($surfaces)
        ->toHaveCount(3)
        ->and($surfaces[1]['processes'])
        ->toHaveCount(2)
        ->and($surfaces[1]['paths'])
        ->toBe([
            'apps/cli/app/One.php',
            'apps/cli/app/Two.php',
        ])
        ->and($surfaces[2]['paths'])
        ->toBe(['apps/gateway/app/Http.php']);
});

it('rejects a collected process that reports runtime drift', function (): void {
    $fpm = observedRecord(
        'gateway',
        'fpm',
        str_repeat('3', 32),
        ['/home/orbit/orbit/apps/gateway/app/Http.php'],
    );
    $fpm['php_version'] = '8.5.11';
    $collector = new ObservedPhpInputCollector(observedTransport([
        'app-dev' => [observedRecord(
            'app-dev',
            'cli',
            str_repeat('1', 32),
            ['/home/orbit/orbit/apps/cli/app/One.php'],
        )],
        'gateway' => [
            observedRecord(
                'gateway',
                'cli',
                str_repeat('2', 32),
                ['/home/orbit/orbit/apps/cli/app/One.php'],
            ),
            $fpm,
        ],
    ]));

    expect(fn () => $collector->collect(
        TopologyTarget::feature('AUX-9', new AttemptId(str_repeat('a', 32))),
        'setup',
        'AUX-9',
        new AttemptId(str_repeat('a', 32)),
        observedCollectedRuntimes(),
        [
            'apps/cli/app/One.php' => ['mode' => '100644', 'type' => 'blob', 'object' => str_repeat('b', 40)],
            'apps/gateway/app/Http.php' => ['mode' => '100644', 'type' => 'blob', 'object' => str_repeat('c', 40)],
        ],
    ))
        ->toThrow(RuntimeException::class, 'invalid process output');
});

it('rejects zero coverage even when another process supplies the required surface', function (): void {
    $collector = new ObservedPhpInputCollector(observedTransport([
        'app-dev' => [
            observedRecord('app-dev', 'cli', str_repeat('1', 32), []),
            observedRecord(
                'app-dev',
                'cli',
                str_repeat('2', 32),
                ['/home/orbit/orbit/apps/cli/app/One.php'],
            ),
        ],
        'gateway' => [
            observedRecord(
                'gateway',
                'cli',
                str_repeat('3', 32),
                ['/home/orbit/orbit/apps/cli/app/One.php'],
            ),
            observedRecord(
                'gateway',
                'fpm',
                str_repeat('4', 32),
                ['/home/orbit/orbit/apps/gateway/app/Http.php'],
            ),
        ],
    ]));

    expect(fn () => $collector->collect(
        TopologyTarget::feature('AUX-9', new AttemptId(str_repeat('a', 32))),
        'setup',
        'AUX-9',
        new AttemptId(str_repeat('a', 32)),
        observedCollectedRuntimes(),
        [
            'apps/cli/app/One.php' => ['mode' => '100644', 'type' => 'blob', 'object' => str_repeat('b', 40)],
            'apps/gateway/app/Http.php' => ['mode' => '100644', 'type' => 'blob', 'object' => str_repeat('c', 40)],
        ],
    ))
        ->toThrow(RuntimeException::class, 'aggregation returned zero coverage on app-dev');
});

it('fails closed for malformed, missing-surface, and untracked process output', function (
    array $records,
    string $message,
): void {
    $collector = new ObservedPhpInputCollector(observedTransport($records));

    expect(fn () => $collector->collect(
        TopologyTarget::feature('AUX-9', new AttemptId(str_repeat('a', 32))),
        'setup',
        'AUX-9',
        new AttemptId(str_repeat('a', 32)),
        observedCollectedRuntimes(),
        ['apps/cli/app/One.php' => ['mode' => '100644', 'type' => 'blob', 'object' => str_repeat('b', 40)]],
    ))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'malformed' => [['app-dev' => ['bad'], 'gateway' => ['bad']], 'invalid process output'],
    'missing surface' => [
        [
            'app-dev' => [observedRecord(
                'app-dev',
                'cli',
                str_repeat('1', 32),
                ['/home/orbit/orbit/apps/cli/app/One.php'],
            )],
            'gateway' => [observedRecord(
                'gateway',
                'cli',
                str_repeat('2', 32),
                ['/home/orbit/orbit/apps/cli/app/One.php'],
            )],
        ],
        'missing required surface gateway:fpm',
    ],
    'untracked' => [
        [
            'app-dev' => [observedRecord(
                'app-dev',
                'cli',
                str_repeat('1', 32),
                ['/home/orbit/orbit/apps/cli/app/Missing.php'],
            )],
            'gateway' => [],
        ],
        'untracked path',
    ],
    'unexpected process surface' => [
        [
            'app-dev' => [
                observedRecord(
                    'app-dev',
                    'cli',
                    str_repeat('1', 32),
                    ['/home/orbit/orbit/apps/cli/app/One.php'],
                ),
                observedRecord(
                    'app-dev',
                    'fpm',
                    str_repeat('2', 32),
                    ['/home/orbit/orbit/apps/cli/app/One.php'],
                ),
            ],
            'gateway' => [
                observedRecord(
                    'gateway',
                    'cli',
                    str_repeat('3', 32),
                    ['/home/orbit/orbit/apps/cli/app/One.php'],
                ),
                observedRecord(
                    'gateway',
                    'fpm',
                    str_repeat('4', 32),
                    ['/home/orbit/orbit/apps/cli/app/One.php'],
                ),
            ],
        ],
        'unexpected surface app-dev:fpm',
    ],
]);
