<?php

declare(strict_types=1);

namespace App\E2E;

use Illuminate\Support\Facades\Process;

/**
 * @mago-expect lint:cyclomatic-complexity,kan-defect,too-many-methods The quarantined host boundary keeps
 * every destructive target and operation check explicit.
 */
final readonly class LegacyRetirementHost
{
    public function __construct(
        private LegacyIncusRevalidator $revalidator,
        private HostRelativeDeleter $deleter,
    ) {}

    /** @return array<string, list<array<string, mixed>>> */
    public function observe(): array
    {
        return $this->frozenObservation();
    }

    /**
     * @param array<string, list<array<string, mixed>>>|null $requested
     * @return array<string, list<array<string, mixed>>>
     */
    public function observeCurrent(?array $requested = null): array
    {
        $frozen = $this->frozenObservation();
        if ($requested !== null) {
            $frozen = $this->requestedObservation($frozen, $requested);
        }
        $incus = [];
        foreach ($frozen as $kind => $resources) {
            if (in_array(
                $kind,
                ['instances', 'snapshots', 'networks', 'pools', 'base_images', 'new_namespace'],
                true,
            )) {
                $incus[$kind] = $resources;
            }
        }
        $current = $this->revalidator->currentBatch($incus, 'delete_instances');
        foreach ($frozen as $kind => $resources) {
            foreach ($resources as $resource) {
                if (in_array(
                    $kind,
                    ['instances', 'snapshots', 'networks', 'pools', 'base_images', 'new_namespace'],
                    true,
                )) {
                    continue;
                } elseif (in_array($kind, ['source_paths', 'manifests', 'locks', 'evidence'], true)) {
                    $path = $resource['path'] ?? null;
                    $root = $resource['safe_root'] ?? null;
                    if (! is_string($path) || $kind !== 'evidence' && ! is_string($root)) {
                        throw new \RuntimeException('The reviewed host path has no exact identity.');
                    }
                    $type = $this->currentFilesystemType($path);
                    if ($type === null) {
                        continue;
                    }
                    $expectedType = $this->expectedFilesystemType($kind);
                    if ($type !== $expectedType || ($resource['filesystem_type'] ?? null) !== $expectedType) {
                        throw new \RuntimeException('The reviewed host path filesystem type changed.');
                    }
                    $pathReal = realpath($path);
                    if (! is_string($pathReal)) {
                        throw new \RuntimeException('The reviewed host path cannot be resolved.');
                    }
                    if ($kind !== 'evidence') {
                        $rootType = $this->currentFilesystemType($root);
                        $rootReal = realpath($root);
                        if (
                            $rootType !== 'directory'
                            || ! is_string($rootReal)
                            || ! str_starts_with($pathReal, rtrim($rootReal, '/').'/')
                        ) {
                            throw new \RuntimeException('The reviewed host path left its safe root.');
                        }
                    }
                    $actualHash = $this->fileDigest($pathReal);
                    if ($actualHash !== ($resource['sha256'] ?? null)) {
                        throw new \RuntimeException('The reviewed host path contents changed.');
                    }
                }

                $current[$kind][] = $resource;
            }
        }

        return $current;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $frozen
     * @param array<string, list<array<string, mixed>>> $requested
     * @return array<string, list<array<string, mixed>>>
     */
    private function requestedObservation(array $frozen, array $requested): array
    {
        $selected = [];
        $seen = [];
        foreach ($requested as $kind => $resources) {
            foreach ($resources as $resource) {
                if (! is_string($kind) || ! is_array($resource)) {
                    throw new \RuntimeException('The requested host observation is invalid.');
                }
                $identity = $this->identity($resource);
                $key = $kind."\0".$identity;
                if (isset($seen[$key])) {
                    throw new \RuntimeException('The requested host observation contains a duplicate resource.');
                }
                $seen[$key] = true;
                foreach ($frozen[$kind] ?? [] as $candidate) {
                    if ($this->identity($candidate) === $identity) {
                        $selected[$kind][] = $candidate;
                        continue 2;
                    }
                }
            }
        }

        return $selected;
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function frozenObservation(): array
    {
        $path = getenv('ORBIT_E2E_LEGACY_OBSERVATION');
        if (! is_string($path)) {
            throw new \RuntimeException('ORBIT_E2E_LEGACY_OBSERVATION must name a protected JSON manifest.');
        }

        /** @var array<string, list<array<string, mixed>>> $value */
        $value = LegacyRetirement::readProtectedJson($path);
        foreach (['source_paths', 'manifests', 'locks', 'evidence'] as $kind) {
            foreach ($value[$kind] ?? [] as &$resource) {
                if (! is_array($resource)) {
                    throw new \RuntimeException('The reviewed host path is invalid.');
                }
                $expectedType = $this->expectedFilesystemType($kind);
                if (
                    array_key_exists('filesystem_type', $resource)
                    && ($resource['filesystem_type'] ?? null) !== $expectedType
                ) {
                    throw new \RuntimeException('The reviewed host path filesystem type is invalid.');
                }
                $resource['filesystem_type'] = $expectedType;
            }
            unset($resource);
        }

        return $value;
    }

    private function fileDigest(string $path): string
    {
        if (is_file($path)) {
            return (string) hash_file('sha256', $path);
        }
        if (! is_dir($path)) {
            return '';
        }
        $entries = [];
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path.'/'.$entry;
            if (is_link($child)) {
                return '';
            }
            $entries[] = $entry.':'.$this->fileDigest($child);
        }
        sort($entries);

        return hash('sha256', implode("\n", $entries));
    }

    private function expectedFilesystemType(string $kind): string
    {
        return match ($kind) {
            'source_paths' => 'directory',
            'manifests', 'locks', 'evidence' => 'file',
            default => throw new \RuntimeException('The reviewed host path kind is invalid.'),
        };
    }

    private function currentFilesystemType(string $path): ?string
    {
        if (
            ! str_starts_with($path, '/')
            || preg_match('/[\x00\r\n\\\\]/', $path) === 1
            || str_contains($path, '//')
            || in_array('.', explode('/', $path), true)
            || in_array('..', explode('/', $path), true)
        ) {
            throw new \RuntimeException('The reviewed host path is unsafe.');
        }
        $cursor = '';
        $type = null;
        foreach (array_filter(explode('/', $path), static fn (string $part): bool => $part !== '') as $part) {
            $cursor .= '/'.$part;
            if (! file_exists($cursor) && ! is_link($cursor)) {
                return null;
            }
            $stat = lstat($cursor);
            if ($stat === false) {
                return null;
            }
            $mode = $stat['mode'] & 0170000;
            if ($mode === 0120000) {
                throw new \RuntimeException('The reviewed host path crosses a symbolic link.');
            }
            $type = match ($mode) {
                0040000 => 'directory',
                0100000 => 'file',
                default => 'other',
            };
        }
        if (! is_string($type)) {
            throw new \RuntimeException('The reviewed host path is unsafe.');
        }

        return $type;
    }

    /** @param array<string, mixed> $resource */
    public function mutate(string $operation, array $resource): void
    {
        $identity = $this->identity($resource);

        if ($this->isIncusOperation($operation)) {
            $this->mutateIncus($operation, $resource, $identity);

            return;
        }

        $this->mutateFile($operation, $resource, $identity);
    }

    /** @param array<string, mixed> $resource */
    private function identity(array $resource): string
    {
        $identity = $resource['identity'] ?? $resource['name'] ?? $resource['path'] ?? null;
        if (
            ! is_string($identity)
            || $identity === ''
            || str_contains($identity, ':')
            || str_starts_with($identity, '-')
            || str_contains($identity, '*')
            || str_contains($identity, '?')
        ) {
            throw new \RuntimeException('The legacy mutation target is unsafe.');
        }

        return $identity;
    }

    private function isIncusOperation(string $operation): bool
    {
        return in_array($operation, ['stop', 'delete_snapshots', 'delete_instances', 'delete_networks'], true);
    }

    /** @param array<string, mixed> $resource */
    private function mutateIncus(string $operation, array $resource, string $identity): void
    {
        $remote = $resource['remote'] ?? null;
        $project = $resource['project'] ?? null;
        if (
            ! is_string($remote)
            || ! is_string($project)
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}\z/', $remote) !== 1
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}\z/', $project) !== 1
        ) {
            throw new \RuntimeException('The legacy Incus target has no exact remote and project identity.');
        }
        $snapshot = null;
        if ($operation === 'delete_snapshots') {
            if (
                preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}\/[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}\z/D', $identity) !== 1
            ) {
                throw new \RuntimeException('The legacy mutation target is unsafe.');
            }
            [$instance, $snapshot] = explode('/', $identity, 2);
            $target = "{$remote}:{$instance}";
        } else {
            if (str_contains($identity, '/')) {
                throw new \RuntimeException('The legacy mutation target is unsafe.');
            }
            $target = "{$remote}:{$identity}";
        }
        $arguments = match ($operation) {
            'stop' => ['incus', '--project', $project, 'stop', $target],
            'delete_snapshots' => ['incus', '--project', $project, 'snapshot', 'delete', $target, (string) $snapshot],
            'delete_instances' => ['incus', '--project', $project, 'delete', $target],
            'delete_networks' => ['incus', '--project', $project, 'network', 'delete', $target],
            default => throw new \RuntimeException('The legacy Incus mutation operation is invalid.'),
        };
        $kind = $operation === 'delete_snapshots'
            ? 'snapshots'
            : ($operation === 'delete_networks' ? 'networks' : 'instances');
        if (Process::timeout(300)->run($arguments)->failed()) {
            throw new \RuntimeException('An exact legacy Incus mutation failed.');
        }
    }

    /** @param array<string, mixed> $resource */
    private function mutateFile(string $operation, array $resource, string $identity): void
    {
        $root = $resource['safe_root'] ?? null;
        if (! is_string($root)) {
            throw new \RuntimeException('The legacy file target has no safe root.');
        }
        $kind = match ($operation) {
            'delete_source_paths' => 'source_paths',
            'delete_manifests' => 'manifests',
            'delete_locks' => 'locks',
            default => throw new \RuntimeException('The legacy mutation operation is invalid.'),
        };
        $this->deleter->delete($kind, $root, $identity);
    }
}
