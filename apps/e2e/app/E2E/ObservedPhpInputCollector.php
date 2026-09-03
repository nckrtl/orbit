<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\AttemptId;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\GuestCommandResult;
use App\E2E\Value\ObservedPhpInputs;
use App\E2E\Value\TopologyTarget;
use JsonException;
use RuntimeException;

/**
 * Orchestrate fail-closed PCOV collection on the disposable checkout roles.
 *
 * @mago-expect lint:cyclomatic-complexity,kan-defect,too-many-methods The collector keeps all external process evidence fail-closed.
 */
final readonly class ObservedPhpInputCollector
{
    private const array ROLES = ['app-dev', 'gateway'];

    private const array RUNTIME_PACKAGES = [
        'php8.5-cli',
        'php8.5-fpm',
        'php8.5-common',
        'php8.5-curl',
        'php8.5-mbstring',
        'php8.5-sqlite3',
        'php8.5-xml',
    ];

    private const string SCRIPT = '/usr/local/bin/observe-php.sh';

    public function __construct(
        private GuestTransport $guests,
    ) {}

    public function normalizeRuntime(TopologyTarget $target): void
    {
        $commands = [];
        foreach (self::ROLES as $role) {
            $commands[$role] = [
                'instance' => $target->instance($role),
                'command' => new GuestCommand([self::SCRIPT, 'prepare', 'runtime'], 900),
            ];
        }
        $this->assertSuccessful($this->guests->execAll($commands), 'Sury PHP runtime preparation failed on');
        $this->runtimeInventory($target, false);
    }

    /** @return list<array{role:string,php_version:string,fpm_version:string,pcov_version:string,package_versions:array<string,string>}> */
    public function prepare(TopologyTarget $target): array
    {
        $commands = [];
        foreach (self::ROLES as $role) {
            $commands[$role] = [
                'instance' => $target->instance($role),
                'command' => new GuestCommand([self::SCRIPT, 'prepare', 'pcov'], 900),
            ];
        }
        $this->assertSuccessful($this->guests->execAll($commands), 'Sury PHP and PCOV preparation failed on');

        $inventory = $this->runtimeInventory($target, true);
        $runtimes = [];
        foreach ($inventory as $runtime) {
            if (! is_string($runtime['pcov_version'])) {
                throw new RuntimeException("Packaged PCOV runtime verification failed on {$runtime['role']}.");
            }
            $runtimes[] = [
                'role' => $runtime['role'],
                'php_version' => $runtime['php_version'],
                'fpm_version' => $runtime['fpm_version'],
                'pcov_version' => $runtime['pcov_version'],
                'package_versions' => $runtime['package_versions'],
            ];
        }

        return $runtimes;
    }

    /** @return list<array{role:string,php_version:string,fpm_version:string,pcov_version:?string,package_versions:array<string,string>}> */
    private function runtimeInventory(TopologyTarget $target, bool $withPcov): array
    {
        $probes = [];
        foreach (self::ROLES as $role) {
            $probes[$role] = [
                'instance' => $target->instance($role),
                'command' => new GuestCommand([
                    self::SCRIPT,
                    'runtime-info',
                    $withPcov ? 'pcov' : 'runtime',
                ]),
            ];
        }
        $results = $this->guests->execAll($probes);
        $packages = self::RUNTIME_PACKAGES;
        if ($withPcov) {
            $packages[] = 'php8.5-pcov';
        }
        $runtimes = [];
        $shared = null;
        foreach (self::ROLES as $role) {
            $result = $results[$role] ?? null;
            if (! $result instanceof GuestCommandResult || ! $result->successful()) {
                throw new RuntimeException("Sury PHP runtime verification failed on {$role}.");
            }
            try {
                $runtime = json_decode($result->stdout, true, 4, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException("Sury PHP runtime verification was malformed on {$role}.", 0, $exception);
            }
            if (
                ! is_array($runtime)
                || array_keys($runtime) !== ['php_version', 'fpm_version', 'pcov_version', 'package_versions']
                || ! is_string($runtime['php_version'])
                || ! is_string($runtime['fpm_version'])
                || $runtime['fpm_version'] !== $runtime['php_version']
                || ($withPcov ? ! is_string($runtime['pcov_version']) : $runtime['pcov_version'] !== null)
                || ! is_array($runtime['package_versions'])
                || array_keys($runtime['package_versions']) !== $packages
                || array_any(
                    $runtime['package_versions'],
                    static fn (mixed $version): bool => ! is_string($version)
                    || $version === ''
                    || str_contains($version, "\n"),
                )
                || count(array_unique(array_slice($runtime['package_versions'], 0, count(self::RUNTIME_PACKAGES))))
                    !== 1
            ) {
                throw new RuntimeException("Sury PHP runtime verification was malformed on {$role}.");
            }
            /** @var string $phpVersion */
            $phpVersion = $runtime['php_version'];
            /** @var string $fpmVersion */
            $fpmVersion = $runtime['fpm_version'];
            /** @var ?string $pcovVersion */
            $pcovVersion = $runtime['pcov_version'];
            /** @var array<string, string> $packageVersions */
            $packageVersions = $runtime['package_versions'];
            $comparison = [
                'php_version' => $phpVersion,
                'fpm_version' => $fpmVersion,
                'pcov_version' => $pcovVersion,
                'package_versions' => $packageVersions,
            ];
            if ($shared !== null && $comparison !== $shared) {
                throw new RuntimeException('Sury PHP and PCOV runtime inventories differ between app-dev and gateway.');
            }
            $shared = $comparison;
            $runtimes[] = ['role' => $role, ...$comparison];
        }

        return $runtimes;
    }

    public function begin(TopologyTarget $target, string $phase, string $issue, AttemptId $attempt): void
    {
        $this->assertPhase($phase);
        $commands = [];
        foreach (self::ROLES as $role) {
            $commands[$role] = [
                'instance' => $target->instance($role),
                'command' => new GuestCommand([
                    self::SCRIPT,
                    'begin',
                    $phase,
                    $role,
                    $issue,
                    $attempt->value,
                ], 120),
            ];
        }
        $this->assertSuccessful($this->guests->execAll($commands), "PCOV {$phase} activation failed on");
        $this->probe($target);
    }

    /**
     * @param array<string, array{mode:string,type:string,object:string}> $entries
     * @param list<array{role:string,php_version:string,fpm_version:string,pcov_version:string,package_versions:array<string,string>}> $runtimes
     * @return list<array{role:string,process_type:string,processes:list<array{id:string,started_at:string,finished_at:string}>,paths:list<string>}>
     * @mago-expect lint:excessive-parameter-list Runtime evidence is checked against every collected process.
     */
    public function collect(
        TopologyTarget $target,
        string $phase,
        string $issue,
        AttemptId $attempt,
        array $runtimes,
        array $entries,
    ): array {
        $this->assertPhase($phase);
        $commands = [];
        foreach (self::ROLES as $role) {
            $commands[$role] = [
                'instance' => $target->instance($role),
                'command' => new GuestCommand([
                    self::SCRIPT,
                    'collect',
                    $phase,
                    $role,
                    $issue,
                    $attempt->value,
                ]),
            ];
        }
        $results = $this->guests->execAll($commands);
        $runtimeByRole = [];
        foreach ($runtimes as $runtime) {
            $runtimeByRole[$runtime['role']] = $runtime;
        }
        /** @var array<string, array{role:string,process_type:string,processes:list<array{id:string,started_at:string,finished_at:string}>,paths:array<string,true>}> $surfaces */
        $surfaces = [];
        foreach (self::ROLES as $role) {
            $result = $results[$role] ?? null;
            if (! $result instanceof GuestCommandResult || ! $result->successful()) {
                $detail = $result instanceof GuestCommandResult ? trim($result->stderr) : 'missing result';
                throw new RuntimeException("PCOV {$phase} output is incomplete on {$role}: {$detail}");
            }
            $runtime = $runtimeByRole[$role] ?? throw new RuntimeException(
                "PCOV {$phase} runtime inventory is missing {$role}.",
            );
            foreach ($this->records($result->stdout, $phase, $role, $issue, $attempt, $runtime, $entries) as $record) {
                $key = $record['role'].':'.$record['process_type'];
                $surfaces[$key] ??= [
                    'role' => $record['role'],
                    'process_type' => $record['process_type'],
                    'processes' => [],
                    'paths' => [],
                ];
                $surfaces[$key]['processes'][] = [
                    'id' => $record['id'],
                    'started_at' => $record['started_at'],
                    'finished_at' => $record['finished_at'],
                ];
                foreach ($record['paths'] as $path) {
                    $surfaces[$key]['paths'][$path] = true;
                }
            }
        }

        $requiredSurfaces = ['app-dev:cli', 'gateway:cli', 'gateway:fpm'];
        foreach (array_keys($surfaces) as $key) {
            if (! in_array($key, $requiredSurfaces, true)) {
                throw new RuntimeException("PCOV {$phase} output contains unexpected surface {$key}.");
            }
        }
        $ordered = [];
        foreach ($requiredSurfaces as $key) {
            if (! isset($surfaces[$key])) {
                throw new RuntimeException("PCOV {$phase} output is missing required surface {$key}.");
            }
            usort(
                $surfaces[$key]['processes'],
                static fn (array $left, array $right): int => $left['id'] <=> $right['id'],
            );
            $paths = array_keys($surfaces[$key]['paths']);
            sort($paths, SORT_STRING);
            $ordered[] = [
                'role' => $surfaces[$key]['role'],
                'process_type' => $surfaces[$key]['process_type'],
                'processes' => $surfaces[$key]['processes'],
                'paths' => $paths,
            ];
        }

        return $ordered;
    }

    public function cleanup(TopologyTarget $target): void
    {
        $commands = [];
        foreach (self::ROLES as $role) {
            $commands[$role] = [
                'instance' => $target->instance($role),
                'command' => new GuestCommand([self::SCRIPT, 'cleanup'], 120),
            ];
        }
        $this->assertSuccessful($this->guests->execAll($commands), 'PCOV cleanup failed on');
    }

    private function probe(TopologyTarget $target): void
    {
        $results = $this->guests->execAll([
            'app-dev-cli' => [
                'instance' => $target->instance('app-dev'),
                'command' => new GuestCommand([self::SCRIPT, 'probe-cli']),
            ],
            'gateway-cli' => [
                'instance' => $target->instance('gateway'),
                'command' => new GuestCommand([self::SCRIPT, 'probe-cli']),
            ],
            'gateway-fpm' => [
                'instance' => $target->instance('gateway'),
                'command' => new GuestCommand([self::SCRIPT, 'probe-fpm']),
            ],
        ]);
        $this->assertSuccessful($results, 'PCOV process-surface probe failed on');
    }

    /**
     * @param array<string, array{mode:string,type:string,object:string}> $entries
     * @param array{role:string,php_version:string,fpm_version:string,pcov_version:string,package_versions:array<string,string>} $runtime
     * @return list<array{role:string,process_type:string,id:string,started_at:string,finished_at:string,paths:list<string>}>
     * @mago-expect lint:excessive-parameter-list Every recorded identity is checked at the guest-output boundary.
     */
    private function records(
        string $output,
        string $phase,
        string $role,
        string $issue,
        AttemptId $attempt,
        array $runtime,
        array $entries,
    ): array {
        try {
            $records = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                "PCOV {$phase} aggregation returned malformed output on {$role}.",
                0,
                $exception,
            );
        }
        if (! is_array($records) || ! array_is_list($records) || $records === []) {
            throw new RuntimeException("PCOV {$phase} aggregation returned no process output on {$role}.");
        }
        $normalized = [];
        foreach ($records as $record) {
            if (
                ! is_array($record)
                || array_keys($record) !== [
                    'schema',
                    'id',
                    'attempt',
                    'issue',
                    'phase',
                    'role',
                    'process_type',
                    'pid',
                    'php_version',
                    'pcov_version',
                    'started_at',
                    'finished_at',
                    'files',
                ]
                || ($record['schema'] ?? null) !== ObservedPhpInputs::SCHEMA
                || ($record['attempt'] ?? null) !== $attempt->value
                || ($record['issue'] ?? null) !== $issue
                || ($record['phase'] ?? null) !== $phase
                || ($record['role'] ?? null) !== $role
                || ! in_array($record['process_type'] ?? null, ['cli', 'fpm'], true)
                || ($record['php_version'] ?? null) !== $runtime['php_version']
                || ($record['pcov_version'] ?? null) !== $runtime['pcov_version']
                || ! is_string($record['id'] ?? null)
                || preg_match('/\A[0-9a-f]{32}\z/D', $record['id']) !== 1
                || ! is_string($record['started_at'] ?? null)
                || ! is_string($record['finished_at'] ?? null)
                || ! is_array($record['files'] ?? null)
                || ! array_is_list($record['files'])
            ) {
                throw new RuntimeException("PCOV {$phase} aggregation returned invalid process output on {$role}.");
            }
            $paths = [];
            foreach ($record['files'] as $path) {
                if (! is_string($path) || ! str_starts_with($path, '/home/orbit/orbit/')) {
                    throw new RuntimeException("PCOV {$phase} observed an unknown guest path on {$role}.");
                }
                $path = substr($path, strlen('/home/orbit/orbit/'));
                $entry = $entries[$path] ?? null;
                if (! is_array($entry) || ($entry['type'] ?? null) !== 'blob') {
                    throw new RuntimeException("PCOV {$phase} observed an untracked path [{$path}] on {$role}.");
                }
                $paths[$path] = true;
            }
            $paths = array_keys($paths);
            sort($paths, SORT_STRING);
            if ($paths === []) {
                continue;
            }
            $normalized[] = [
                'role' => $role,
                'process_type' => $record['process_type'],
                'id' => $record['id'],
                'started_at' => $record['started_at'],
                'finished_at' => $record['finished_at'],
                'paths' => $paths,
            ];
        }

        return $normalized;
    }

    /** @param array<string, GuestCommandResult> $results */
    private function assertSuccessful(array $results, string $message): void
    {
        $failed = [];
        foreach ($results as $label => $result) {
            if (! $result->successful()) {
                $failed[] = $label;
            }
        }
        if ($failed !== []) {
            throw new RuntimeException($message.' '.implode(', ', $failed).'.');
        }
    }

    private function assertPhase(string $phase): void
    {
        if (! in_array($phase, ObservedPhpInputs::PHASES, true)) {
            throw new RuntimeException('The PCOV proof phase is invalid.');
        }
    }
}
