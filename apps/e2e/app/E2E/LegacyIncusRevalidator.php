<?php

declare(strict_types=1);

namespace App\E2E;

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
 *
 * @mago-expect lint:cyclomatic-complexity,kan-defect,too-many-methods Exact resource validation is kept at the destructive-operation boundary.
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
     * @param array<string, list<array<string, mixed>>> $groups
     * @return array<string, list<array<string, mixed>>>
     */
    public function currentBatch(array $groups, ?string $operation = null): array
    {
        $seen = [];
        foreach ($groups as $kind => $resources) {
            foreach ($resources as $expected) {
                $remote = $expected['remote'] ?? null;
                $project = $expected['project'] ?? null;
                $identity = $expected['identity'] ?? $expected['name'] ?? null;
                if (! is_string($kind) || ! is_string($remote) || ! is_string($project) || ! is_string($identity)) {
                    throw new RuntimeException('The reviewed Incus resource has no exact scope or identity.');
                }
                $key = $kind."\0".$remote."\0".$project."\0".$identity;
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

        $results = Process::pool(function (Pool $pool) use ($commands): void {
            foreach ($commands as $label => $command) {
                if (! array_is_list($command) || array_filter($command, is_string(...)) !== $command) {
                    throw new RuntimeException('The live Incus resource command is invalid.');
                }
                /** @var list<string> $command */
                $pool->as($label)->timeout(300)->command($command);
            }
        })->run();
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
            if ($result->failed() && $this->isExactMissingEnvelope($result->output())) {
                continue;
            }
            if ($result->failed()) {
                throw new RuntimeException('The live Incus resource read failed.');
            }
            $resource = $this->parseCurrent($kind, $expected, $result, $operation);
            $current[$kind][] = $resource;
        }

        /** @var array<string, list<array<string, mixed>>> $current */
        return $current;
    }

    /** @param array<string, mixed> $expected @return array<string, mixed>|null */
    public function current(string $kind, array $expected, ?string $operation = null): ?array
    {
        $remote = $expected['remote'] ?? null;
        $project = $expected['project'] ?? null;
        $identity = $expected['identity'] ?? $expected['name'] ?? null;
        if (! is_string($remote) || ! is_string($project) || ! is_string($identity)) {
            throw new RuntimeException('The reviewed Incus resource has no exact scope or identity.');
        }

        $command = $this->queryCommand($kind, $expected);
        if (! array_is_list($command) || array_filter($command, is_string(...)) !== $command) {
            throw new RuntimeException('The live Incus resource command is invalid.');
        }
        /** @var list<string> $command */
        $result = $this->run($command, true);
        if ($result === null) {
            return null;
        }

        return $this->parseCurrent($kind, $expected, $result, $operation);
    }

    /** @param array<string, mixed> $expected @return list<string> */
    private function queryCommand(string $kind, array $expected): array
    {
        $remote = $expected['remote'] ?? null;
        $project = $expected['project'] ?? null;
        $identity = $expected['identity'] ?? $expected['name'] ?? null;
        if (! is_string($remote) || ! is_string($project) || ! is_string($identity)) {
            throw new RuntimeException('The reviewed Incus resource has no exact scope or identity.');
        }
        $path = match ($kind) {
            'instances' => '/1.0/instances/'.$this->exactName($identity, 'instance'),
            'networks' => '/1.0/networks/'.$this->exactName($identity, 'network'),
            'snapshots' => $this->snapshotPath($identity),
            'pools' => '/1.0/storage-pools/'.$this->exactName((string) ($expected['identity'] ?? $identity), 'pool'),
            'base_images' => '/1.0/images/'.$this->exactName((string) ($expected['fingerprint'] ?? $identity), 'image'),
            'new_namespace' => '/1.0/projects/'
                .$this->exactName((string) ($expected['identity'] ?? $identity), 'project'),
            default => throw new RuntimeException('The live Incus resource kind is invalid.'),
        };

        return ['incus', 'query', "{$remote}:{$path}?project={$project}"];
    }

    /** @param array<string, mixed> $expected */
    private function parseCurrent(string $kind, array $expected, ProcessResult $result, ?string $operation): array
    {
        $identity = $expected['identity'] ?? $expected['name'] ?? null;
        if (! is_string($identity)) {
            throw new RuntimeException('The reviewed Incus resource has no exact identity.');
        }
        try {
            $live = json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Incus returned malformed live resource JSON.', 0, $exception);
        }
        $live = $this->liveObject($live);
        if (($live['type'] ?? null) === 'sync') {
            $metadata = $live['metadata'] ?? null;
            $live = $this->liveObject($metadata);
        }

        $this->assertExact($kind, $identity, $expected, $live, $operation);
        $current = $expected;
        if ($kind === 'instances' && is_string($live['status'] ?? null)) {
            $current['status'] = strtoupper($live['status']);
        }

        return $current;
    }

    /** @param list<string> $command */
    private function run(array $command, bool $allowMissing = false): ?ProcessResult
    {
        try {
            $result = Process::timeout(300)->run($command);
        } catch (\Throwable $exception) {
            throw new RuntimeException('The live Incus resource read could not run.', 0, $exception);
        }
        if ($result->failed()) {
            if ($allowMissing && $this->isExactMissingEnvelope($result->output())) {
                return null;
            }
            throw new RuntimeException('The live Incus resource read failed.');
        }

        if ($allowMissing && $this->isExactMissingEnvelope($result->output())) {
            return null;
        }

        return $result;
    }

    private function isExactMissingEnvelope(string $output): bool
    {
        try {
            $value = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }
        if (! is_array($value) || ($value['type'] ?? null) !== 'error') {
            return false;
        }
        $statusCode = $value['status_code'] ?? $value['metadata']['status_code'] ?? null;

        return $statusCode === 404;
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

    /** @param array<string, mixed> $expected @param array<array-key, mixed> $live */
    private function assertExact(string $kind, string $identity, array $expected, array $live, ?string $operation): void
    {
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

    /** @param array<string, mixed> $metadata @return array<string, mixed> */
    private function stableMetadata(array $metadata): array
    {
        foreach ($metadata as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'volatile.')) {
                unset($metadata[$key]);
            } elseif (is_array($value) && ! array_is_list($value)) {
                /** @var array<string, mixed> $value */
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
        $liveIdentity = $kind === 'base_images'
            ? $live['fingerprint'] ?? $live['name'] ?? null
            : $live['name'] ?? $live['id'] ?? null;
        if ($kind === 'new_namespace') {
            $liveIdentity = $live['name'] ?? $live['id'] ?? null;
        }
        if ($liveIdentity !== ($kind === 'base_images' ? $identity : $identity)) {
            throw new RuntimeException('The live Incus resource identity changed.');
        }
    }

    /** @param array<array-key, mixed> $live @return list<string> */
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
        $remote = $expected['remote'] ?? null;
        $project = $expected['project'] ?? null;
        $identity = $expected['identity'] ?? $expected['name'] ?? null;
        if (! is_string($remote) || ! is_string($project) || ! is_string($identity)) {
            throw new RuntimeException('The reviewed Incus resource has no exact scope or identity.');
        }

        $label = 'incus-'.hash('sha256', $kind."\0".$remote."\0".$project."\0".$identity);
        if (! $this->isBatchLabel($label)) {
            throw new RuntimeException('Incus parallel query label is invalid.');
        }

        return $label;
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
