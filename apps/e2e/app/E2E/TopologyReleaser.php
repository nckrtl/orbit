<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\OperationId;
use App\E2E\Value\ReleaseResult;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyTarget;
use RuntimeException;

/** @mago-expect lint:cyclomatic-complexity,kan-defect Exact ordered cleanup keeps every ownership guard visible. */
final readonly class TopologyReleaser
{
    public function __construct(
        private IncusHost $host,
        private TopologyManifestStore $manifests,
        private AtomicJsonStore $state,
        private StatePaths $paths,
    ) {}

    public function release(string $issue): ReleaseResult
    {
        $target = new TopologyTarget($issue);
        $operation = new OperationId(bin2hex(random_bytes(16)));
        $lock = new OperationLock($this->paths);
        if (! $lock->acquire('topology-'.$issue, $operation)) {
            throw new RuntimeException('The issue topology is locked.');
        }
        try {
            return $this->releaseLocked($target);
        } finally {
            $lock->release();
        }
    }

    /** @mago-expect lint:halstead Exact release evidence requires explicit ordered mutations. */
    private function releaseLocked(TopologyTarget $target): ReleaseResult
    {
        $issue = $target->issue;
        $topology = $this->manifests->read($target);
        if ($topology === null) {
            $retained = $this->state->read('releases/'.$issue.'.json');
            if ($retained !== null) {
                $previous = ReleaseResult::fromArray($retained);

                return new ReleaseResult(
                    bin2hex(random_bytes(16)),
                    $previous->evidenceId,
                    [],
                    [...$previous->released, ...$previous->alreadyAbsent],
                );
            }
            throw new RuntimeException('The exact feature topology manifest does not exist.');
        }

        $released = [];
        $absent = [];
        $instances = [];
        foreach (TopologyProfile::ROLES as $role) {
            $name = $target->instance($role);
            if (($topology->instances[$role] ?? null) !== $name) {
                throw new RuntimeException('A manifest resource identity changed before release.');
            }
            $instance = $this->host->instance($name);
            if ($instance === null) {
                $absent[] = $name;
                continue;
            }
            $this->assertOwnership($instance->metadata, $issue, $name);
            $instances[$role] = $instance;
        }
        if ($topology->network !== $target->network()) {
            throw new RuntimeException('The manifest network identity changed before release.');
        }
        $network = $this->host->network($target->network());
        if ($network === null) {
            $absent[] = $topology->network;
        }
        if ($network !== null) {
            $this->assertOwnership($network->metadata, $issue, $target->network());
        }
        $sourceOperations = $this->sourceOperations($issue);
        $sourceArtifacts = [];
        foreach ($instances as $instance) {
            foreach ($sourceOperations as $sourceOperation) {
                $path = '/var/lib/orbit-e2e/source/'.$sourceOperation;
                $probe = $this->host->exec($instance->name, new GuestCommand(['test', '-e', $path]));
                $sourceArtifacts[$instance->name][$sourceOperation] = $probe->successful();
            }
        }

        foreach ($instances as $instance) {
            foreach ($sourceOperations as $sourceOperation) {
                if (! ($sourceArtifacts[$instance->name][$sourceOperation] ?? false)) {
                    $absent[] = 'source:'.$instance->name.':'.$sourceOperation;
                    continue;
                }
                $cleanup = $this->host->exec($instance->name, new GuestCommand([
                    'rm',
                    '-rf',
                    '--',
                    '/var/lib/orbit-e2e/source/'.$sourceOperation,
                ]));
                if (! $cleanup->successful()) {
                    throw new RuntimeException('Exact transferred source cleanup failed.');
                }
                $released[] = 'source:'.$instance->name.':'.$sourceOperation;
            }
        }
        foreach (array_reverse(TopologyProfile::ROLES) as $role) {
            $instance = $instances[$role] ?? null;
            if ($instance !== null && $instance->isRunning()) {
                $this->host->stop($instance->name);
                $released[] = 'stopped:'.$instance->name;
            }
        }
        foreach (array_reverse(TopologyProfile::ROLES) as $role) {
            $instance = $instances[$role] ?? null;
            if ($instance === null) {
                continue;
            }
            $this->host->deleteInstance($instance->name);
            if ($this->host->instance($instance->name) !== null) {
                throw new RuntimeException('Incus instance storage did not disappear after deletion.');
            }
            $released[] = 'deleted:'.$instance->name;
        }

        if ($network !== null) {
            $this->host->deleteNetwork($target->network());
            $released[] = 'deleted:'.$target->network();
        }

        foreach (['leases/'.$issue.'.json', 'topologies/'.$issue.'.json'] as $relative) {
            $path = $this->paths->path($relative);
            if (is_file($path) && ! unlink($path)) {
                throw new RuntimeException('Unable to remove exact topology state.');
            }
        }

        $result = new ReleaseResult(bin2hex(random_bytes(16)), bin2hex(random_bytes(16)), $released, $absent);
        $this->state->write('releases/'.$issue.'.json', $result->toArray());

        return $result;
    }

    /** @return list<string> */
    private function sourceOperations(string $issue): array
    {
        $lease = $this->state->read('leases/'.$issue.'.json');
        $operations = $lease['source_operation_ids'] ?? [];
        if (! is_array($operations) || ! array_is_list($operations)) {
            throw new RuntimeException('The topology source inventory is invalid.');
        }
        $validated = [];
        foreach ($operations as $operation) {
            if (! is_string($operation) || preg_match('/\A[0-9a-f]{32}\z/D', $operation) !== 1) {
                throw new RuntimeException('A topology source identity is invalid.');
            }
            $validated[] = $operation;
        }

        return $validated;
    }

    /** @param array<string, string> $metadata */
    private function assertOwnership(array $metadata, string $issue, string $resource): void
    {
        if (
            ($metadata['user.orbit.e2e.owner'] ?? null) !== 'orbit-e2e'
            || ($metadata['user.orbit.e2e.issue'] ?? null) !== $issue
        ) {
            throw new RuntimeException("Incus resource {$resource} ownership does not match the exact issue.");
        }
    }
}
