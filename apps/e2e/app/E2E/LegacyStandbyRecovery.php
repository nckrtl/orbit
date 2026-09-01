<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\AtomicJsonStore;
use App\E2E\Value\AttemptId;
use App\E2E\Value\IncusInstance;
use App\E2E\Value\IncusNetwork;
use App\E2E\Value\LegacyStandbyInventory;
use App\E2E\Value\OperationId;
use App\E2E\Value\StandbyGeneration;
use App\E2E\Value\StandbyIdentity;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyTarget;
use InvalidArgumentException;
use RuntimeException;

/**
 * Prove exact ownership and retain evidence for the bounded schema-4/5 standby recovery.
 *
 * @mago-expect lint:cyclomatic-complexity,kan-defect,too-many-methods Exact authorization and evidence validation stay at one transaction boundary.
 */
final readonly class LegacyStandbyRecovery
{
    private const array PHASES = [
        'instances_pending',
        'instances_verified',
        'network_pending',
        'network_verified',
        'manifests_pending',
        'manifests_verified',
        'construction_pending',
        'construction_cleanup_pending',
        'construction_cleanup_verified',
        'construction_verified',
        'failed',
    ];

    public function __construct(
        private IncusHost $host,
        private StandbyManifestStore $manifests,
        private AtomicJsonStore $state,
        private OperationId $operation,
        private StandbyIdentity $identity,
    ) {}

    public function authorize(): LegacyStandbyInventory
    {
        $promoted = $this->manifests->promoted();
        if ($promoted === null) {
            throw new RuntimeException(
                'Legacy standby recovery requires a readable unbound schema-4/5 promoted manifest. '
                .'Preserve the named resources and restore the matching manifest before retrying.',
            );
        }
        if (! in_array(
            $promoted->manifestSchema,
            [StandbyGeneration::LEGACY_SCHEMA, StandbyGeneration::SCHEMA],
            true,
        )) {
            throw new RuntimeException(
                'Legacy standby recovery requires a readable unbound schema-4/5 promoted manifest. '
                .'Preserve the named resources and use the recovery procedure for that manifest schema.',
            );
        }

        $recorded = $this->manifests->recorded();
        $target = TopologyTarget::standby($this->identity);
        $roles = [];
        foreach (TopologyProfile::ROLES as $role) {
            $roles[$target->instance($role)] = ['role' => $role, 'copy' => false];
            $roles[$target->instance($role).'-next'] = ['role' => $role, 'copy' => true];
        }

        $instances = $this->host->instances(array_keys($roles));
        $serializedInstances = [];
        foreach ($instances as $name => $instance) {
            $identity = $roles[$name];
            $this->assertInstance($instance, $identity['role'], $identity['copy']);
            $serializedInstances[$name] = $this->instanceArray($instance);
        }
        ksort($serializedInstances, SORT_STRING);

        $snapshots = $instances === [] ? [] : $this->host->ownedSnapshotNames(array_keys($instances));
        foreach ($instances as $name => $_instance) {
            $identity = $roles[$name];
            if ($identity['copy']) {
                continue;
            }
            $expected = $promoted->snapshots[$identity['role']];
            $names = array_column($snapshots[$name] ?? [], 'name');
            if (! in_array($expected, $names, true)) {
                throw new RuntimeException("Incus instance {$name} does not contain promoted snapshot {$expected}.");
            }
        }

        $network = $this->host->network($this->identity->network());
        if ($network !== null) {
            $this->assertNetwork($network, array_keys($instances));
        }
        if ($instances === [] && $network === null) {
            throw new RuntimeException('No exact configured standby resources are present for legacy recovery.');
        }

        return new LegacyStandbyInventory(
            [...$this->host->scope(), 'standby_namespace' => $this->identity->namespace],
            $promoted->toArray(),
            array_map(static fn ($generation): array => $generation->toArray(), $recorded),
            $serializedInstances,
            $snapshots,
            $network === null ? null : $this->networkArray($network),
        );
    }

    public function start(string $mainSha, LegacyStandbyInventory $inventory): void
    {
        if (preg_match('/\A[a-f0-9]{40}\z/D', $mainSha) !== 1) {
            throw new RuntimeException('The legacy recovery SHA is invalid.');
        }
        $existing = $this->state->read('standby/recovery.json');
        if ($existing !== null) {
            $this->archiveCompleted($existing);
        }

        $this->state->write('standby/recovery.json', [
            'schema' => 1,
            'operation_id' => $this->operation->value,
            'main_sha' => $mainSha,
            'inventory_sha256' => $inventory->sha256(),
            'inventory' => $inventory->toArray(),
            'phase' => 'authorized',
            'history' => [[
                'phase' => 'authorized',
                'evidence' => ['resources' => $inventory->resourceNames()],
            ]],
        ]);
    }

    public function completed(): bool
    {
        return ($this->state->read('standby/recovery.json')['phase'] ?? null) === 'construction_verified';
    }

    public function interruptedConstructionOperation(): ?OperationId
    {
        $record = $this->state->read('standby/recovery.json');
        if ($record === null || ($record['phase'] ?? null) === 'construction_verified') {
            return null;
        }
        $history = $record['history'] ?? null;
        if (! is_array($history) || ! array_is_list($history)) {
            throw new RuntimeException('The retained legacy recovery record is invalid.');
        }

        $operation = null;
        foreach ($history as $entry) {
            if (! is_array($entry) || ! is_array($entry['evidence'] ?? null)) {
                throw new RuntimeException('The retained legacy recovery record is invalid.');
            }
            if (in_array($entry['phase'] ?? null, ['construction_pending', 'construction_cleanup_pending'], true)) {
                $candidate = $entry['evidence']['operation_id'] ?? null;
                if (is_string($candidate)) {
                    $operation = $candidate;
                }
            }
        }
        if ($operation === null) {
            return null;
        }
        try {
            return new OperationId($operation);
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException(
                'The retained construction operation identity is invalid.',
                previous: $exception,
            );
        }
    }

    /** @return array{instances:bool,network:bool,manifests:bool} */
    public function resumableBoundaries(): array
    {
        $record = $this->state->read('standby/recovery.json');
        $history = $record['history'] ?? [];
        if (! is_array($history) || ! array_is_list($history)) {
            throw new RuntimeException('The retained legacy recovery record is invalid.');
        }
        $phases = [];
        foreach ($history as $entry) {
            if (! is_array($entry) || ! is_string($entry['phase'] ?? null)) {
                throw new RuntimeException('The retained legacy recovery record is invalid.');
            }
            $phases[] = $entry['phase'];
        }

        return [
            'instances' => in_array('instances_pending', $phases, true),
            'network' => in_array('network_pending', $phases, true),
            'manifests' => in_array('manifests_pending', $phases, true),
        ];
    }

    public function resume(string $mainSha): ?LegacyStandbyInventory
    {
        $record = $this->state->read('standby/recovery.json');
        if ($record === null) {
            return null;
        }
        if (($record['main_sha'] ?? null) !== $mainSha) {
            throw new RuntimeException(
                'The retained legacy recovery does not match the requested main SHA; use its recorded next action.',
            );
        }
        $inventoryValue = $record['inventory'] ?? null;
        $digest = $record['inventory_sha256'] ?? null;
        if (! is_array($inventoryValue) || ! is_string($digest)) {
            throw new RuntimeException('The retained legacy recovery record is invalid.');
        }
        try {
            $inventory = LegacyStandbyInventory::fromArray($inventoryValue);
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException('The retained legacy recovery record is invalid.', previous: $exception);
        }
        if (! hash_equals($inventory->sha256(), $digest)) {
            throw new RuntimeException('The retained legacy recovery inventory digest does not match.');
        }
        if (($record['phase'] ?? null) === 'construction_verified') {
            throw new RuntimeException('Legacy standby recovery is already complete; run bin/e2e-standby status.');
        }
        $history = $record['history'] ?? null;
        $previousOperation = $record['operation_id'] ?? null;
        $previousPhase = $record['phase'] ?? null;
        if (
            ! is_array($history)
            || ! array_is_list($history)
            || ! is_string($previousOperation)
            || ! is_string($previousPhase)
        ) {
            throw new RuntimeException('The retained legacy recovery record is invalid.');
        }
        $record['operation_id'] = $this->operation->value;
        $record['phase'] = 'resumed';
        $history[] = [
            'phase' => 'resumed',
            'evidence' => [
                'previous_operation_id' => $previousOperation,
                'previous_phase' => $previousPhase,
            ],
        ];
        $record['history'] = $history;
        $this->state->write('standby/recovery.json', $record);

        return $inventory;
    }

    /** @param array<string, mixed> $evidence */
    public function record(string $phase, array $evidence): void
    {
        if (! in_array($phase, self::PHASES, true)) {
            throw new RuntimeException('The legacy recovery evidence phase is invalid.');
        }
        $record = $this->state->read('standby/recovery.json');
        if (
            $record === null
            || ($record['schema'] ?? null) !== 1
            || ($record['operation_id'] ?? null) !== $this->operation->value
            || ! is_array($record['history'] ?? null)
            || ! array_is_list($record['history'])
        ) {
            throw new RuntimeException('The retained legacy recovery record is invalid.');
        }
        $record['phase'] = $phase;
        $record['history'][] = ['phase' => $phase, 'evidence' => $evidence];
        $this->state->write('standby/recovery.json', $record);
    }

    /** @return array<array-key, mixed>|null */
    public function retained(): ?array
    {
        return $this->state->read('standby/recovery.json');
    }

    /** @param array<array-key, mixed> $record */
    private function archiveCompleted(array $record): void
    {
        if (($record['phase'] ?? null) !== 'construction_verified') {
            throw new RuntimeException(
                'A retained legacy standby recovery record already exists; follow its recorded next action.',
            );
        }
        $operation = $record['operation_id'] ?? null;
        $inventoryValue = $record['inventory'] ?? null;
        $digest = $record['inventory_sha256'] ?? null;
        if (
            ! is_string($operation)
            || preg_match('/\A[a-f0-9]{32}\z/D', $operation) !== 1
            || ! is_array($inventoryValue)
            || ! is_string($digest)
        ) {
            throw new RuntimeException('The completed legacy recovery record is invalid.');
        }
        try {
            $inventory = LegacyStandbyInventory::fromArray($inventoryValue);
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException('The completed legacy recovery record is invalid.', previous: $exception);
        }
        if (! hash_equals($inventory->sha256(), $digest)) {
            throw new RuntimeException('The completed legacy recovery inventory digest does not match.');
        }

        $archive = "standby/recoveries/{$operation}.json";
        $retained = $this->state->read($archive);
        if ($retained !== null && $retained !== $record) {
            throw new RuntimeException('The completed legacy recovery archive conflicts with retained evidence.');
        }
        if ($retained === null) {
            $this->state->write($archive, $record);
        }
        if ($this->state->read($archive) !== $record) {
            throw new RuntimeException('The completed legacy recovery evidence could not be archived.');
        }
        $this->state->delete('standby/recovery.json');
    }

    private function assertInstance(IncusInstance $instance, string $role, bool $copy): void
    {
        if (($instance->metadata['user.orbit.e2e.owner'] ?? null) !== 'orbit-e2e') {
            throw new RuntimeException("Incus instance {$instance->name} ownership does not match.");
        }
        $operation = $this->operationId($instance->metadata, $instance->name);
        if ($instance->network !== $this->identity->network()) {
            throw new RuntimeException("Incus instance {$instance->name} network identity does not match.");
        }
        $expectedMac = TopologyTarget::standby($this->identity)->mac($role);
        if ($instance->mac !== $expectedMac) {
            throw new RuntimeException("Incus instance {$instance->name} MAC identity does not match.");
        }
        if ($instance->disks !== []) {
            throw new RuntimeException("Incus instance {$instance->name} has unexpected disk devices.");
        }

        $issue = $instance->metadata['user.orbit.e2e.issue'] ?? null;
        $expectedMetadata = [
            'user.orbit.e2e.owner' => 'orbit-e2e',
            'user.orbit.e2e.operation' => $operation->value,
        ];
        $metadata = $instance->metadata;
        if (! is_string($issue) || $issue === '') {
            ksort($metadata, SORT_STRING);
            ksort($expectedMetadata, SORT_STRING);
            if ($metadata !== $expectedMetadata) {
                throw new RuntimeException("Incus instance {$instance->name} ownership identity is incomplete.");
            }

            return;
        }
        if (! $copy) {
            throw new RuntimeException("Incus instance {$instance->name} belongs to feature issue {$issue}.");
        }
        $attempt = $instance->metadata['user.orbit.e2e.attempt'] ?? null;
        try {
            TopologyTarget::assertIssue($issue);
            new AttemptId(is_string($attempt) ? $attempt : '');
        } catch (InvalidArgumentException) {
            throw new RuntimeException("Incus instance {$instance->name} promotion identity is incomplete.");
        }
        $expectedMetadata['user.orbit.e2e.issue'] = $issue;
        $expectedMetadata['user.orbit.e2e.attempt'] = $attempt;
        ksort($metadata, SORT_STRING);
        ksort($expectedMetadata, SORT_STRING);
        if ($metadata !== $expectedMetadata) {
            throw new RuntimeException("Incus instance {$instance->name} promotion identity is incomplete.");
        }
    }

    /** @param array<string, string> $metadata */
    private function operationId(array $metadata, string $resource): OperationId
    {
        $operation = $metadata['user.orbit.e2e.operation'] ?? null;
        try {
            return new OperationId(is_string($operation) ? $operation : '');
        } catch (InvalidArgumentException) {
            throw new RuntimeException("Standby resource {$resource} operation identity is incomplete.");
        }
    }

    /** @param list<string> $instanceNames */
    private function assertNetwork(IncusNetwork $network, array $instanceNames): void
    {
        if (($network->metadata['user.orbit.e2e.owner'] ?? null) !== 'orbit-e2e') {
            throw new RuntimeException("Incus network {$network->name} ownership does not match.");
        }
        $operation = $this->operationId($network->metadata, $network->name);
        $expected = [
            'user.orbit.e2e.owner' => 'orbit-e2e',
            'user.orbit.e2e.operation' => $operation->value,
            'ipv4.address' => "10.232.{$this->identity->slot}.1/24",
            'ipv4.nat' => 'true',
            'ipv4.dhcp.ranges' => "10.232.{$this->identity->slot}.10-10.232.{$this->identity->slot}.12",
            'ipv6.address' => 'none',
            'raw.dnsmasq' => 'port=0',
        ];
        $configuration = $network->config;
        ksort($configuration, SORT_STRING);
        ksort($expected, SORT_STRING);
        if ($configuration !== $expected) {
            throw new RuntimeException("Incus network {$network->name} configuration identity does not match.");
        }
        if (count($network->usedBy) !== count(array_unique($network->usedBy))) {
            throw new RuntimeException("Incus network {$network->name} user identity is ambiguous.");
        }
        foreach ($network->usedBy as $user) {
            $url = parse_url($user);
            $path = is_array($url) ? $url['path'] ?? null : null;
            $query = is_array($url) ? $url['query'] ?? '' : '';
            parse_str(is_string($query) ? $query : '', $parameters);
            $name = is_string($path) ? basename($path) : '';
            if (! in_array($name, $instanceNames, true) || ($parameters['project'] ?? null) !== $network->project) {
                throw new RuntimeException("Incus network {$network->name} has an unexpected user {$user}.");
            }
        }
    }

    /** @return array<string, mixed> */
    private function instanceArray(IncusInstance $instance): array
    {
        return [
            'remote' => $instance->remote,
            'project' => $instance->project,
            'name' => $instance->name,
            'pool' => $instance->pool,
            'metadata' => $instance->metadata,
            'status' => $instance->status,
            'status_code' => $instance->statusCode,
            'network' => $instance->network,
            'mac' => $instance->mac,
            'disks' => $instance->disks,
        ];
    }

    /** @return array<string, mixed> */
    private function networkArray(IncusNetwork $network): array
    {
        return [
            'remote' => $network->remote,
            'project' => $network->project,
            'name' => $network->name,
            'metadata' => $network->metadata,
            'config' => $network->config,
            'used_by' => $network->usedBy,
        ];
    }
}
