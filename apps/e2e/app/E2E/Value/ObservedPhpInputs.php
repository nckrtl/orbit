<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/**
 * Complete, normalized PCOV evidence collected from disposable proof guests.
 *
 * @mago-expect lint:cyclomatic-complexity,kan-defect The immutable evidence schema validates every nested process boundary.
 */
final readonly class ObservedPhpInputs
{
    public const int SCHEMA = 2;

    public const string COLLECTOR = 'pcov';

    public const int COLLECTOR_VERSION = 2;

    public const array PHASES = ['setup', 'acceptance'];

    public const array PACKAGES = [
        'php8.5-cli',
        'php8.5-fpm',
        'php8.5-common',
        'php8.5-curl',
        'php8.5-mbstring',
        'php8.5-sqlite3',
        'php8.5-xml',
        'php8.5-pcov',
    ];

    /** @var list<array{role:string,php_version:string,fpm_version:string,pcov_version:string,package_versions:array<string,string>}> */
    public array $runtimes;

    /** @var array{setup:list<array{role:string,process_type:string,processes:list<array{id:string,started_at:string,finished_at:string}>,paths:list<string>}>,acceptance:list<array{role:string,process_type:string,processes:list<array{id:string,started_at:string,finished_at:string}>,paths:list<string>}>} */
    public array $phases;

    /**
     * @param list<array{role:string,php_version:string,fpm_version:string,pcov_version:string,package_versions:array<string,string>}> $runtimes
     * @param array{setup:list<array{role:string,process_type:string,processes:list<array{id:string,started_at:string,finished_at:string}>,paths:list<string>}>,acceptance:list<array{role:string,process_type:string,processes:list<array{id:string,started_at:string,finished_at:string}>,paths:list<string>}>} $phases
     */
    public function __construct(array $runtimes, array $phases)
    {
        $this->runtimes = $this->validateRuntimes($runtimes);
        $this->phases = $this->validatePhases($phases);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'collector' => self::COLLECTOR,
            'collector_version' => self::COLLECTOR_VERSION,
            'runtimes' => $this->runtimes,
            'phases' => $this->phases,
        ];
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        if (
            array_keys($value) !== ['schema', 'collector', 'collector_version', 'runtimes', 'phases']
            || ($value['schema'] ?? null) !== self::SCHEMA
            || ($value['collector'] ?? null) !== self::COLLECTOR
            || ($value['collector_version'] ?? null) !== self::COLLECTOR_VERSION
            || ! is_array($value['runtimes'] ?? null)
            || ! is_array($value['phases'] ?? null)
        ) {
            throw new InvalidArgumentException('The observed PHP input schema is invalid.');
        }

        /** @var list<array{role:string,php_version:string,fpm_version:string,pcov_version:string,package_versions:array<string,string>}> $runtimes */
        $runtimes = $value['runtimes'];
        /** @var array{setup:list<array{role:string,process_type:string,processes:list<array{id:string,started_at:string,finished_at:string}>,paths:list<string>}>,acceptance:list<array{role:string,process_type:string,processes:list<array{id:string,started_at:string,finished_at:string}>,paths:list<string>}>} $phases */
        $phases = $value['phases'];

        return new self($runtimes, $phases);
    }

    /** @return array<string, true> */
    public function paths(): array
    {
        $paths = [];
        foreach ($this->phases as $surfaces) {
            foreach ($surfaces as $surface) {
                foreach ($surface['paths'] as $path) {
                    $paths[$path] = true;
                }
            }
        }

        return $paths;
    }

    /**
     * @param list<array{role:string,php_version:string,fpm_version:string,pcov_version:string,package_versions:array<string,string>}> $runtimes
     * @return list<array{role:string,php_version:string,fpm_version:string,pcov_version:string,package_versions:array<string,string>}>
     */
    private function validateRuntimes(array $runtimes): array
    {
        if (! array_is_list($runtimes)) {
            throw new InvalidArgumentException('The observed PHP runtime inventory is invalid.');
        }
        $roles = [];
        $shared = null;
        foreach ($runtimes as $runtime) {
            if (
                ! is_array($runtime)
                || array_keys($runtime) !== [
                    'role',
                    'php_version',
                    'fpm_version',
                    'pcov_version',
                    'package_versions',
                ]
                || ! in_array($runtime['role'], ['app-dev', 'gateway'], true)
                || ! is_string($runtime['php_version'])
                || preg_match('/\A8\.5\.[0-9]+(?:[^\r\n]*)?\z/D', $runtime['php_version']) !== 1
                || ! is_string($runtime['fpm_version'])
                || $runtime['fpm_version'] !== $runtime['php_version']
                || ! is_string($runtime['pcov_version'])
                || preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+(?:[^\r\n]*)?\z/D', $runtime['pcov_version']) !== 1
                || ! is_array($runtime['package_versions'])
                || array_keys($runtime['package_versions']) !== self::PACKAGES
                || array_any(
                    $runtime['package_versions'],
                    static fn (mixed $version): bool => ! is_string($version)
                    || $version === ''
                    || str_contains($version, "\n"),
                )
                || count(array_unique(array_slice($runtime['package_versions'], 0, count(self::PACKAGES) - 1))) !== 1
                || isset($roles[$runtime['role']])
            ) {
                throw new InvalidArgumentException('An observed PHP runtime entry is invalid.');
            }
            $comparison = $runtime;
            unset($comparison['role']);
            if ($shared !== null && $comparison !== $shared) {
                throw new InvalidArgumentException('The observed PHP runtime inventories are not identical.');
            }
            $shared = $comparison;
            $roles[$runtime['role']] = true;
        }
        if (array_keys($roles) !== ['app-dev', 'gateway']) {
            throw new InvalidArgumentException('The observed PHP runtime inventory is incomplete.');
        }

        return $runtimes;
    }

    /**
     * @param array{setup:list<array{role:string,process_type:string,processes:list<array{id:string,started_at:string,finished_at:string}>,paths:list<string>}>,acceptance:list<array{role:string,process_type:string,processes:list<array{id:string,started_at:string,finished_at:string}>,paths:list<string>}>} $phases
     * @return array{setup:list<array{role:string,process_type:string,processes:list<array{id:string,started_at:string,finished_at:string}>,paths:list<string>}>,acceptance:list<array{role:string,process_type:string,processes:list<array{id:string,started_at:string,finished_at:string}>,paths:list<string>}>}
     */
    private function validatePhases(array $phases): array
    {
        if (array_keys($phases) !== self::PHASES) {
            throw new InvalidArgumentException('The observed PHP phase inventory is invalid.');
        }
        foreach ($phases as $phase => $surfaces) {
            if (! array_is_list($surfaces)) {
                throw new InvalidArgumentException("The observed PHP {$phase} surfaces are invalid.");
            }
            $keys = [];
            foreach ($surfaces as $surface) {
                if (
                    ! is_array($surface)
                    || array_keys($surface) !== ['role', 'process_type', 'processes', 'paths']
                    || ! is_string($surface['role'])
                    || ! is_string($surface['process_type'])
                    || ! is_array($surface['processes'])
                    || ! is_array($surface['paths'])
                ) {
                    throw new InvalidArgumentException("An observed PHP {$phase} surface is invalid.");
                }
                $key = $surface['role'].':'.$surface['process_type'];
                if (! in_array($key, ['app-dev:cli', 'gateway:cli', 'gateway:fpm'], true) || isset($keys[$key])) {
                    throw new InvalidArgumentException("An observed PHP {$phase} surface is invalid.");
                }
                $keys[$key] = true;
                $this->assertProcesses($surface['processes'], $phase, $key);
                $this->assertPaths($surface['paths'], $phase, $key);
            }
            if (array_keys($keys) !== ['app-dev:cli', 'gateway:cli', 'gateway:fpm']) {
                throw new InvalidArgumentException("The observed PHP {$phase} surfaces are incomplete.");
            }
        }

        return $phases;
    }

    /** @param list<array{id:string,started_at:string,finished_at:string}> $processes */
    private function assertProcesses(array $processes, string $phase, string $surface): void
    {
        if (! array_is_list($processes) || $processes === []) {
            throw new InvalidArgumentException("Observed PHP {$phase} surface {$surface} has no process evidence.");
        }
        $ids = [];
        foreach ($processes as $process) {
            if (
                ! is_array($process)
                || array_keys($process) !== ['id', 'started_at', 'finished_at']
                || ! is_string($process['id'])
                || preg_match('/\A[0-9a-f]{32}\z/D', $process['id']) !== 1
                || ! $this->timestamp($process['started_at'] ?? null)
                || ! $this->timestamp($process['finished_at'] ?? null)
                || $process['finished_at'] < $process['started_at']
                || isset($ids[$process['id']])
            ) {
                throw new InvalidArgumentException(
                    "Observed PHP {$phase} surface {$surface} has invalid process evidence.",
                );
            }
            $ids[$process['id']] = true;
        }
    }

    /** @param list<string> $paths */
    private function assertPaths(array $paths, string $phase, string $surface): void
    {
        if (! array_is_list($paths) || $paths === []) {
            throw new InvalidArgumentException("Observed PHP {$phase} surface {$surface} has no tracked paths.");
        }
        $sorted = $paths;
        sort($sorted, SORT_STRING);
        if (
            $paths !== array_values(array_unique($sorted))
            || ! array_all($paths, fn (mixed $path): bool => is_string($path)
            && $this->safePath($path))
        ) {
            throw new InvalidArgumentException("Observed PHP {$phase} surface {$surface} paths are invalid.");
        }
    }

    private function timestamp(mixed $value): bool
    {
        return (
            is_string($value)
            && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}Z\z/D', $value) === 1
        );
    }

    private function safePath(string $path): bool
    {
        return (
            $path !== ''
            && ! str_starts_with($path, '/')
            && ! str_contains($path, "\0")
            && ! str_contains($path, '\\')
            && ! in_array('', explode('/', $path), true)
            && ! in_array('.', explode('/', $path), true)
            && ! in_array('..', explode('/', $path), true)
        );
    }
}
