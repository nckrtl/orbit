<?php

declare(strict_types=1);

namespace App\E2E;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use JsonException;
use RuntimeException;

/**
 * Reads one exact Incus resource immediately before a destructive operation.
 *
 * This boundary deliberately does not use a list operation. A reviewed
 * observation is evidence only; the command result is the authorization fact.
 *
 * @mago-expect lint:cyclomatic-complexity,kan-defect Exact resource validation is kept at the destructive-operation boundary.
 */
final readonly class LegacyIncusRevalidator
{
    /** @param array<string, mixed> $expected */
    public function assertCurrent(string $kind, array $expected, ?string $operation = null): void
    {
        $remote = $expected['remote'] ?? null;
        $project = $expected['project'] ?? null;
        $identity = $expected['identity'] ?? $expected['name'] ?? null;
        if (! is_string($remote) || ! is_string($project) || ! is_string($identity)) {
            throw new RuntimeException('The reviewed Incus resource has no exact scope or identity.');
        }

        $this->assertName($remote, 'remote');
        $this->assertName($project, 'project');
        $path = match ($kind) {
            'instances' => '/1.0/instances/'.$this->exactName($identity, 'instance'),
            'networks' => '/1.0/networks/'.$this->exactName($identity, 'network'),
            'snapshots' => $this->snapshotPath($identity),
            default => throw new RuntimeException('The live Incus resource kind is invalid.'),
        };
        $result = $this->run(['incus', 'query', "{$remote}:{$path}?project={$project}"]);

        try {
            $live = json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Incus returned malformed live resource JSON.', 0, $exception);
        }
        $live = $this->liveObject($live);

        $this->assertExact($kind, $identity, $expected, $live, $operation);
    }

    /** @param list<string> $command */
    private function run(array $command): ProcessResult
    {
        try {
            $result = Process::timeout(300)->run($command);
        } catch (\Throwable $exception) {
            throw new RuntimeException('The live Incus resource read could not run.', 0, $exception);
        }
        if ($result->failed()) {
            throw new RuntimeException('The live Incus resource read failed.');
        }

        return $result;
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
        if ($kind !== 'snapshots') {
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
        if (! $this->isSubset($expectedMetadata, $liveMetadata)) {
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
        foreach ($reviewedDependencies as $dependency) {
            if (! in_array($dependency, $liveDependencies, true)) {
                throw new RuntimeException('The live Incus resource dependencies changed.');
            }
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
    }

    /** @param array<string, mixed> $expected @param array<string, mixed> $live */
    private function isSubset(array $expected, array $live): bool
    {
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $live)) {
                return false;
            }
            if (is_array($value)) {
                $liveValue = $live[$key];
                if (! is_array($liveValue) || array_is_list($value) || array_is_list($liveValue)) {
                    return false;
                }
                /** @var array<string, mixed> $value */
                /** @var array<string, mixed> $liveValue */
                if (! $this->isSubset($value, $liveValue)) {
                    return false;
                }
            } elseif ($live[$key] !== $value) {
                return false;
            }
        }

        return true;
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
        if (($live['name'] ?? null) !== $identity) {
            throw new RuntimeException('The live Incus resource identity changed.');
        }
    }

    /** @param array<array-key, mixed> $live @return list<string> */
    private function dependencies(string $kind, array $live): array
    {
        $dependencies = $live['dependencies'] ?? null;
        if ($dependencies === null && $kind === 'instances') {
            $devices = $live['devices'] ?? $live['expanded_devices'] ?? [];
            $network = is_array($devices) ? $devices['eth0']['network'] ?? null : null;
            $dependencies = $network === null ? [] : [$network];
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
