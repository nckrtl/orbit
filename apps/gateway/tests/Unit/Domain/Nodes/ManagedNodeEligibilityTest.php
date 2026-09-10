<?php

declare(strict_types=1);

use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Database\Eloquent\Collection;

it('requires the supported platform and both managed identities', function (array $attributes, bool $eligible): void {
    $node = new Node(array_merge([
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'wireguard_ip' => '10.44.0.2',
        'ssh_host_fingerprint' => 'SHA256:managed',
    ], $attributes));
    $node->setRelation('roles', new Collection);

    expect(new ManagedNodeEligibility()->allows($node))->toBe($eligible);
})->with([
    'managed Linux node' => [[], true],
    'inactive node' => [['status' => LifecycleStatus::Failed], false],
    'unsupported platform' => [['platform' => 'darwin'], false],
    'missing WireGuard identity' => [['wireguard_ip' => null], false],
    'blank WireGuard identity' => [['wireguard_ip' => ''], false],
    'missing pinned SSH identity' => [['ssh_host_fingerprint' => null], false],
    'blank pinned SSH identity' => [['ssh_host_fingerprint' => ''], false],
]);

it('uses active or provisioning managed roles as stored SSH management intent', function (
    LifecycleStatus $roleStatus,
    bool $eligible,
): void {
    $node = new Node([
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'wireguard_ip' => '10.44.0.1',
        'ssh_host_fingerprint' => null,
    ]);
    $node->setRelation('roles', new Collection([
        new NodeRole([
            'role' => RoleName::Gateway,
            'status' => $roleStatus,
        ]),
    ]));

    expect(new ManagedNodeEligibility()->allows($node))->toBe($eligible);
})->with([
    'active managed role' => [LifecycleStatus::Active, true],
    'provisioning managed role' => [LifecycleStatus::Provisioning, true],
    'failed managed role' => [LifecycleStatus::Failed, false],
    'removing managed role' => [LifecycleStatus::Removing, false],
]);

it('keeps pinned SSH management evidence for Doctor in every node lifecycle', function (
    LifecycleStatus $nodeStatus,
    bool $exporterEligible,
): void {
    $node = new Node([
        'status' => $nodeStatus,
        'platform' => 'linux',
        'wireguard_ip' => '10.44.0.1',
        'ssh_host_fingerprint' => 'SHA256:managed',
    ]);
    $node->setRelation('roles', new Collection);
    $eligibility = new ManagedNodeEligibility;

    expect($eligibility->allows($node))
        ->toBe($exporterEligible)
        ->and($eligibility->isManagedForObservation($node))
        ->toBeTrue();
})->with([
    'provisioning Node' => [LifecycleStatus::Provisioning, false],
    'active Node' => [LifecycleStatus::Active, true],
    'failed Node' => [LifecycleStatus::Failed, false],
    'removing Node' => [LifecycleStatus::Removing, false],
]);

it('keeps every stored managed role lifecycle as legacy Doctor evidence', function (
    LifecycleStatus $roleStatus,
): void {
    $node = new Node([
        'status' => LifecycleStatus::Failed,
        'platform' => 'linux',
        'wireguard_ip' => '10.44.0.1',
        'ssh_host_fingerprint' => null,
    ]);
    $node->setRelation('roles', new Collection([
        new NodeRole([
            'role' => RoleName::Gateway,
            'status' => $roleStatus,
        ]),
    ]));
    $eligibility = new ManagedNodeEligibility;

    expect($eligibility->allows($node))
        ->toBeFalse()
        ->and($eligibility->isManagedForObservation($node))
        ->toBeTrue();
})->with([
    'provisioning role' => LifecycleStatus::Provisioning,
    'active role' => LifecycleStatus::Active,
    'failed role' => LifecycleStatus::Failed,
    'removing role' => LifecycleStatus::Removing,
]);

it('requires supported managed network identity and stored management evidence for Doctor', function (
    array $attributes,
    bool $managed,
): void {
    $node = new Node(array_merge([
        'status' => LifecycleStatus::Failed,
        'platform' => 'linux',
        'wireguard_ip' => '10.44.0.1',
        'ssh_host_fingerprint' => 'SHA256:managed',
    ], $attributes));
    $node->setRelation('roles', new Collection);

    expect(new ManagedNodeEligibility()->isManagedForObservation($node))->toBe($managed);
})->with([
    'managed identity' => [[], true],
    'unsupported platform' => [['platform' => 'darwin'], false],
    'missing WireGuard identity' => [['wireguard_ip' => null], false],
    'blank WireGuard identity' => [['wireguard_ip' => ''], false],
    'missing SSH management evidence' => [['ssh_host_fingerprint' => null], false],
]);
