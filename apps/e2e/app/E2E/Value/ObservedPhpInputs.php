<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/**
 * Complete, normalized PCOV evidence collected from disposable proof guests.
 */
final readonly class ObservedPhpInputs
{
    public const int SCHEMA = 2;

    public const string COLLECTOR = 'pcov';

    public const int COLLECTOR_VERSION = 2;

    public const array PHASES = ['setup', 'acceptance'];

    public const array PACKAGES = PhpRuntimeInventory::PACKAGES;

    /** @var list<array{role:string,php_version:string,fpm_version:string,pcov_version:string,package_versions:array<string,string>}> */
    public array $runtimes;

    /** @var array{setup:list<array{role:string,process_type:string,processes:list<array{id:string,started_at:string,finished_at:string}>,paths:list<string>}>,acceptance:list<array{role:string,process_type:string,processes:list<array{id:string,started_at:string,finished_at:string}>,paths:list<string>}>} */
    public array $phases;

    /**
     * @param  array<array-key, mixed>  $runtimes
     * @param  array<array-key, mixed>  $phases
     */
    public function __construct(array $runtimes, array $phases)
    {
        $this->runtimes = PhpRuntimeInventory::pcovRequired($runtimes)->pcovRuntimes();
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

        $runtimes = $value['runtimes'];
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
     * @param  array<array-key, mixed>  $phases
     * @return array{setup:list<array{role:string,process_type:string,processes:list<array{id:string,started_at:string,finished_at:string}>,paths:list<string>}>,acceptance:list<array{role:string,process_type:string,processes:list<array{id:string,started_at:string,finished_at:string}>,paths:list<string>}>}
     */
    private function validatePhases(array $phases): array
    {
        if (array_keys($phases) !== self::PHASES) {
            throw new InvalidArgumentException('The observed PHP phase inventory is invalid.');
        }

        return [
            'setup' => $this->phaseSurfaces($phases['setup'] ?? null, 'setup'),
            'acceptance' => $this->phaseSurfaces($phases['acceptance'] ?? null, 'acceptance'),
        ];
    }

    /**
     * @return list<array{role: string, process_type: string, processes: list<array{id: string, started_at: string, finished_at: string}>, paths: list<string>}>
     */
    private function phaseSurfaces(mixed $surfaces, string $phase): array
    {
        if (! is_array($surfaces) || ! array_is_list($surfaces)) {
            throw new InvalidArgumentException("The observed PHP {$phase} surfaces are invalid.");
        }
        $validated = [];
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
            $role = $surface['role'];
            $processType = $surface['process_type'];
            $key = $role.':'.$processType;
            if (! in_array($key, ['app-dev:cli', 'gateway:cli', 'gateway:fpm'], true) || isset($keys[$key])) {
                throw new InvalidArgumentException("An observed PHP {$phase} surface is invalid.");
            }
            $keys[$key] = true;
            $validated[] = [
                'role' => $role,
                'process_type' => $processType,
                'processes' => $this->processes($surface['processes'], $phase, $key),
                'paths' => $this->trackedPaths($surface['paths'], $phase, $key),
            ];
        }
        if (array_keys($keys) !== ['app-dev:cli', 'gateway:cli', 'gateway:fpm']) {
            throw new InvalidArgumentException("The observed PHP {$phase} surfaces are incomplete.");
        }

        return $validated;
    }

    /**
     * @param  array<array-key, mixed>  $processes
     * @return list<array{id: string, started_at: string, finished_at: string}>
     */
    private function processes(array $processes, string $phase, string $surface): array
    {
        if (! array_is_list($processes) || $processes === []) {
            throw new InvalidArgumentException("Observed PHP {$phase} surface {$surface} has no process evidence.");
        }
        $validated = [];
        $ids = [];
        foreach ($processes as $process) {
            $id = is_array($process) ? ($process['id'] ?? null) : null;
            $startedAt = is_array($process) ? ($process['started_at'] ?? null) : null;
            $finishedAt = is_array($process) ? ($process['finished_at'] ?? null) : null;
            if (
                ! is_array($process)
                || array_keys($process) !== ['id', 'started_at', 'finished_at']
                || ! is_string($id)
                || preg_match('/\A[0-9a-f]{32}\z/D', $id) !== 1
                || ! is_string($startedAt)
                || ! is_string($finishedAt)
                || ! $this->timestamp($startedAt)
                || ! $this->timestamp($finishedAt)
                || $finishedAt < $startedAt
                || isset($ids[$id])
            ) {
                throw new InvalidArgumentException(
                    "Observed PHP {$phase} surface {$surface} has invalid process evidence.",
                );
            }
            $ids[$id] = true;
            $validated[] = [
                'id' => $id,
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
            ];
        }

        return $validated;
    }

    /**
     * @param  array<array-key, mixed>  $paths
     * @return list<string>
     */
    private function trackedPaths(array $paths, string $phase, string $surface): array
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
        $validated = [];
        foreach ($paths as $path) {
            if (! is_string($path)) {
                throw new InvalidArgumentException("Observed PHP {$phase} surface {$surface} paths are invalid.");
            }
            $validated[] = $path;
        }

        return $validated;
    }

    private function timestamp(mixed $value): bool
    {
        return
            is_string($value)
            && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}Z\z/D', $value) === 1;
    }

    private function safePath(string $path): bool
    {
        return
            $path !== ''
            && ! str_starts_with($path, '/')
            && ! str_contains($path, "\0")
            && ! str_contains($path, '\\')
            && ! in_array('', explode('/', $path), true)
            && ! in_array('.', explode('/', $path), true)
            && ! in_array('..', explode('/', $path), true);
    }
}
