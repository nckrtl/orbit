<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\PreservedIncusReference;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use JsonException;
use RuntimeException;

/**
 * Reads one exact Incus resource immediately before a destructive operation.
 *
 * This boundary deliberately does not use a list operation. A reviewed
 * observation is evidence only; the command result is the authorization fact.
 */
final readonly class LegacyIncusRevalidator
{
    /** @param array<string, mixed> $expected */
    public function assertCurrent(string $kind, array $expected, ?string $operation = null): void
    {
        if ($this->current($kind, $expected, $operation) === null) {
            throw new RuntimeException('The reviewed Incus resource no longer exists.');
        }
    }

    /** @param array<string, mixed> $expected */
    public function isCurrent(string $kind, array $expected, ?string $operation = null): bool
    {
        return $this->current($kind, $expected, $operation) !== null;
    }

    /**
     * @param  array<array-key, list<array<string, mixed>>>  $groups
     * @return array<string, list<array<string, mixed>>>
     */
    public function currentBatch(array $groups, ?string $operation = null): array
    {
        $seen = [];
        foreach ($groups as $kind => $resources) {
            foreach ($resources as $expected) {
                if (! is_string($kind)) {
                    throw new RuntimeException('The reviewed Incus resource has no exact kind.');
                }
                $key = $this->referenceKey($kind, $expected);
                if (isset($seen[$key])) {
                    throw new RuntimeException('The reviewed Incus batch contains a duplicate resource.');
                }
                $seen[$key] = true;
            }
        }

        /** @var array<string, list<string>> $commands */
        $commands = [];
        $references = [];
        foreach ($groups as $kind => $resources) {
            foreach ($resources as $expected) {
                $label = $this->batchLabel($kind, $expected);
                $commands[$label] = $this->queryCommand($kind, $expected);
                $references[$label] = [$kind, $expected];
            }
        }

        try {
            $results = Process::pool(function (Pool $pool) use ($commands): void {
                foreach ($commands as $label => $command) {
                    /** @var list<string> $command */
                    $pool->as($label)->timeout(300)->command($command);
                }
            })->run()->collect()->all();
        } catch (\Throwable $exception) {
            throw new RuntimeException('The live Incus resource read could not run.', 0, $exception);
        }
        $resultLabels = [];
        foreach ($results as $label => $_result) {
            if (! is_string($label) || ! $this->isBatchLabel($label) || ! array_key_exists($label, $references)) {
                throw new RuntimeException('Incus parallel query result label is invalid.');
            }
            $resultLabels[$label] = true;
        }
        foreach (array_keys($references) as $label) {
            if (! isset($resultLabels[$label])) {
                throw new RuntimeException('Incus parallel query result label is missing.');
            }
        }
        $current = [];
        foreach ($references as $label => [$kind, $expected]) {
            $result = $results[$label] ?? null;
            if (! $result instanceof ProcessResult) {
                throw new RuntimeException('Incus parallel query result is invalid.');
            }
            $live = $this->classifyResult($result);
            if ($live === null) {
                continue;
            }
            $resource = $this->parseCurrent($kind, $expected, $live, $operation);
            $current[$kind][] = $resource;
        }

        /** @var array<string, list<array<string, mixed>>> $current */
        return $current;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @return array<string, mixed>|null
     */
    public function current(string $kind, array $expected, ?string $operation = null): ?array
    {
        $remote = $expected['remote'] ?? null;
        $project = $expected['project'] ?? null;
        $identity = $expected['identity'] ?? $expected['name'] ?? null;
        if (! is_string($remote) || ! is_string($project) || ! is_string($identity)) {
            throw new RuntimeException('The reviewed Incus resource has no exact scope or identity.');
        }

        $command = $this->queryCommand($kind, $expected);
        /** @var list<string> $command */
        $result = $this->run($command);
        $live = $this->classifyResult($result);
        if ($live === null) {
            return null;
        }

        return $this->parseCurrent($kind, $expected, $live, $operation);
    }

    /**
     * @param  array<string, mixed>  $expected
     * @return list<string>
     */
    private function queryCommand(string $kind, array $expected): array
    {
        [$remote, $project, $identity] = $this->scopedIdentity($kind, $expected);
        $path = PreservedIncusReference::supports($kind)
            ? PreservedIncusReference::fromResource($kind, $expected)->queryPath()
            : match ($kind) {
                'instances' => '/1.0/instances/'.$this->exactName($identity, 'instance'),
                'networks' => '/1.0/networks/'.$this->exactName($identity, 'network'),
                'snapshots' => $this->snapshotPath($identity),
                'new_namespace' => '/1.0/projects/'
                    .$this->exactName((string) ($expected['identity'] ?? $identity), 'project'),
                default => throw new RuntimeException('The live Incus resource kind is invalid.'),
            };

        return ['incus', 'query', '--raw', "{$remote}:{$path}?project={$project}"];
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $live
     * @return array<string, mixed>
     */
    private function parseCurrent(string $kind, array $expected, array $live, ?string $operation): array
    {
        $identity = $expected['identity'] ?? $expected['name'] ?? null;
        if (! is_string($identity)) {
            throw new RuntimeException('The reviewed Incus resource has no exact identity.');
        }

        $this->assertExact($kind, $identity, $expected, $live, $operation);
        $current = $expected;
        if ($kind === 'instances' && is_string($live['status'] ?? null)) {
            $current['status'] = strtoupper($live['status']);
        }

        return $current;
    }

    /** @param list<string> $command */
    private function run(array $command): ProcessResult
    {
        try {
            $result = Process::timeout(300)->run($command);
        } catch (\Throwable $exception) {
            throw new RuntimeException('The live Incus resource read could not run.', 0, $exception);
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    private function classifyResult(ProcessResult $result): ?array
    {
        if ($result->failed()) {
            throw new RuntimeException('The live Incus resource read failed.');
        }
        try {
            $value = json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Incus returned malformed live resource JSON.', 0, $exception);
        }
        $envelope = $this->liveObject($value);
        if (($envelope['type'] ?? null) === 'error') {
            if (($envelope['error_code'] ?? null) === 404) {
                return null;
            }

            throw new RuntimeException('The live Incus resource read failed.');
        }
        if (($envelope['type'] ?? null) !== 'sync') {
            throw new RuntimeException('Incus returned an invalid live resource envelope.');
        }

        return $this->liveObject($envelope['metadata'] ?? null);
    }

    /** @return array<string, mixed> */
    private function liveObject(mixed $value): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new RuntimeException('Incus returned an invalid live resource object.');
        }
        foreach (array_keys($value) as $key) {
            if (! is_string($key)) {
                throw new RuntimeException('Incus returned an invalid live resource object.');
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $live
     */
    private function assertExact(string $kind, string $identity, array $expected, array $live, ?string $operation): void
    {
        if (PreservedIncusReference::supports($kind)) {
            if (! PreservedIncusReference::fromResource($kind, $expected)->matchesLive($live)) {
                throw new RuntimeException('The live Incus resource identity changed.');
            }

            return;
        }
        if (! in_array($kind, ['snapshots', 'pools', 'base_images', 'new_namespace'], true)) {
            $liveType = $live['type'] ?? $live['kind'] ?? null;
            $validTypes = match ($kind) {
                'instances' => ['virtual-machine', 'instance'],
                'networks' => ['network', 'bridge', 'ovn', 'physical', 'macvlan', 'sriov'],
                default => throw new RuntimeException('The live Incus resource kind is invalid.'),
            };
            if (! is_string($liveType) || ! in_array(strtolower($liveType), $validTypes, true)) {
                throw new RuntimeException('The live Incus resource kind changed.');
            }
        }

        $this->assertIdentity($kind, $identity, $live);
        if (array_key_exists('status', $expected)) {
            $status = $live['status'] ?? null;
            $reviewedStatus = $expected['status'];
            $statusMatches =
                is_string($reviewedStatus) && is_string($status) && strtoupper($status) === strtoupper($reviewedStatus);
            $quarantinedInstance =
                $kind === 'instances'
                && $operation === 'delete_instances'
                && is_string($reviewedStatus)
                && strtoupper($reviewedStatus) === 'RUNNING'
                && is_string($status)
                && strtoupper($status) === 'STOPPED';
            if (! $statusMatches && ! $quarantinedInstance) {
                throw new RuntimeException('The live Incus resource status changed.');
            }
        }

        $expectedMetadata = $expected['metadata'] ?? [];
        $liveMetadata = $live['metadata'] ?? $live['config'] ?? null;
        if (! is_array($expectedMetadata) || $expectedMetadata !== [] && array_is_list($expectedMetadata)) {
            throw new RuntimeException('The reviewed Incus resource metadata is invalid.');
        }
        if (! is_array($liveMetadata) || $liveMetadata !== [] && array_is_list($liveMetadata)) {
            throw new RuntimeException('The live Incus resource metadata changed.');
        }
        /** @var array<string, mixed> $expectedMetadata */
        /** @var array<string, mixed> $liveMetadata */
        if ($this->stableMetadata($expectedMetadata) !== $this->stableMetadata($liveMetadata)) {
            throw new RuntimeException('The live Incus resource metadata changed.');
        }

        $expectedDependencies = $expected['dependencies'] ?? [];
        $liveDependencies = $this->dependencies($kind, $live);
        if (! is_array($expectedDependencies) || ! array_is_list($expectedDependencies)) {
            throw new RuntimeException('The live Incus resource dependencies changed.');
        }
        $reviewedDependencies = [];
        foreach ($expectedDependencies as $dependency) {
            if (! is_string($dependency)) {
                throw new RuntimeException('The live Incus resource dependencies changed.');
            }
            $reviewedDependencies[] = $dependency;
        }
        $reviewedDependencies = array_values(array_unique($reviewedDependencies));
        $liveDependencies = array_values(array_unique($liveDependencies));
        sort($reviewedDependencies);
        sort($liveDependencies);
        if ($reviewedDependencies !== $liveDependencies) {
            throw new RuntimeException('The live Incus resource dependencies changed.');
        }

        if (array_key_exists('owner', $expected)) {
            $owner = $live['owner'] ?? $liveMetadata['owner'] ?? null;
            if ($owner !== $expected['owner']) {
                throw new RuntimeException('The live Incus resource ownership changed.');
            }
        }
        if (array_key_exists('namespace', $expected)) {
            $namespace = $live['namespace'] ?? $liveMetadata['namespace'] ?? null;
            if ($namespace !== $expected['namespace']) {
                throw new RuntimeException('The live Incus resource namespace changed.');
            }
        }
        if (isset($expected['mac'])) {
            $mac = $expected['mac'];
            $devices = $live['devices'] ?? $live['expanded_devices'] ?? null;
            $liveMac = is_array($devices) && is_array($devices['eth0'] ?? null)
                ? $devices['eth0']['hwaddr'] ?? null
                : null;
            if (! is_string($mac) || ! is_string($liveMac) || strtolower($mac) !== strtolower($liveMac)) {
                throw new RuntimeException('The live Incus resource MAC changed.');
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $metadata
     * @return array<array-key, mixed>
     */
    private function stableMetadata(array $metadata): array
    {
        foreach ($metadata as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'volatile.')) {
                unset($metadata[$key]);
            } elseif (is_array($value) && ! array_is_list($value)) {
                $metadata[$key] = $this->stableMetadata($value);
            }
        }

        ksort($metadata);

        return $metadata;
    }

    /** @param array<array-key, mixed> $live */
    private function assertIdentity(string $kind, string $identity, array $live): void
    {
        if ($kind === 'snapshots') {
            if (preg_match('/\A([^\/]+)\/([^\/]+)\z/D', $identity, $parts) !== 1) {
                throw new RuntimeException('The reviewed snapshot identity is invalid.');
            }
            $name = $live['name'] ?? null;
            if (! is_string($name) || $name !== $parts[2]) {
                throw new RuntimeException('The live Incus snapshot identity changed.');
            }

            return;
        }
        $liveIdentity = $live['name'] ?? $live['id'] ?? null;
        if ($kind === 'new_namespace') {
            $liveIdentity = $live['name'] ?? $live['id'] ?? null;
        }
        if ($liveIdentity !== $identity) {
            throw new RuntimeException('The live Incus resource identity changed.');
        }
    }

    /**
     * @param  array<array-key, mixed>  $live
     * @return list<string>
     */
    private function dependencies(string $kind, array $live): array
    {
        $dependencies = $live['dependencies'] ?? null;
        if ($dependencies === null && $kind === 'instances') {
            $devices = $live['devices'] ?? $live['expanded_devices'] ?? [];
            $dependencies = [];
            if (is_array($devices)) {
                foreach ($devices as $device) {
                    if (is_array($device) && is_string($network = $device['network'] ?? null)) {
                        $dependencies[] = $network;
                    }
                }
            }
        }
        if ($dependencies === null && $kind === 'networks') {
            $dependencies = $live['used_by'] ?? [];
        }
        $dependencies ??= [];
        if (! is_array($dependencies) || ! array_is_list($dependencies)) {
            throw new RuntimeException('Incus returned invalid live resource dependencies.');
        }
        $validated = [];
        foreach ($dependencies as $dependency) {
            if (! is_string($dependency) || $dependency === '') {
                throw new RuntimeException('Incus returned invalid live resource dependencies.');
            }
            $validated[] = $dependency;
        }

        return $validated;
    }

    private function exactName(string $identity, string $kind): string
    {
        $this->assertName($identity, $kind);

        return $identity;
    }

    private function assertName(string $identity, string $kind): void
    {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}\z/D', $identity) !== 1) {
            throw new RuntimeException("The reviewed Incus {$kind} identity is invalid.");
        }
    }

    /** @param array<string, mixed> $expected */
    private function batchLabel(string $kind, array $expected): string
    {
        $label = 'incus-'.hash('sha256', $this->referenceKey($kind, $expected));
        if (! $this->isBatchLabel($label)) {
            throw new RuntimeException('Incus parallel query label is invalid.');
        }

        return $label;
    }

    /** @param array<string, mixed> $expected */
    private function referenceKey(string $kind, array $expected): string
    {
        if (PreservedIncusReference::supports($kind)) {
            return PreservedIncusReference::fromResource($kind, $expected)->key();
        }

        [$remote, $project, $identity] = $this->scopedIdentity($kind, $expected);

        return $kind."\0".$remote."\0".$project."\0".$identity;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @return array{string, string, string}
     */
    private function scopedIdentity(string $kind, array $expected): array
    {
        $remote = $expected['remote'] ?? null;
        $project = $expected['project'] ?? null;
        $identity = $expected['identity'] ?? $expected['name'] ?? null;
        if (
            ! is_string($remote)
            || ! is_string($project)
            || ! is_string($identity)
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}\z/D', $remote) !== 1
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}\z/D', $project) !== 1
        ) {
            throw new RuntimeException('The reviewed Incus resource has no exact scope or identity.');
        }

        return [$remote, $project, $identity];
    }

    private function isBatchLabel(mixed $label): bool
    {
        return is_string($label) && preg_match('/\Aincus-[a-f0-9]{64}\z/D', $label) === 1;
    }

    private function snapshotPath(string $identity): string
    {
        if (
            preg_match(
                '/\A([a-zA-Z0-9][a-zA-Z0-9_.-]{0,62})\/([a-zA-Z0-9][a-zA-Z0-9_.-]{0,62})\z/D',
                $identity,
                $parts,
            ) !== 1
        ) {
            throw new RuntimeException('The reviewed Incus snapshot identity is invalid.');
        }

        return "/1.0/instances/{$parts[1]}/snapshots/{$parts[2]}";
    }
}
