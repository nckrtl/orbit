<?php

declare(strict_types=1);

use App\E2E\Value\AttemptId;
use App\E2E\Value\TopologyNode;
use App\E2E\Value\TopologyNodePurpose;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologySnapshotIdentity;
use App\E2E\Value\TopologyTarget;

describe('TopologyTarget', function () {
    it('maps topology slots and roles to deterministic IPv4 addresses', function () {
        expect(TopologyTarget::ipv4For(1, 'gateway'))
            ->toBe('10.232.1.10')
            ->and(TopologyTarget::ipv4For(200, 'app-dev'))
            ->toBe('10.232.200.11')
            ->and(TopologyTarget::ipv4For(100, 'app-prod'))
            ->toBe('10.232.100.12');
    });

    it('rejects invalid topology slots and roles', function (int $slot, string $role) {
        expect(fn () => TopologyTarget::ipv4For($slot, $role))
            ->toThrow(InvalidArgumentException::class);
    })->with([
        'slot below range' => [0, 'gateway'],
        'slot above range' => [201, 'gateway'],
        'unknown role' => [1, 'unknown'],
    ]);

    it('rejects an invalid issue identity', function (string $issue) {
        expect(fn () => TopologyTarget::feature($issue, AttemptId::generate()))
            ->toThrow(InvalidArgumentException::class);
    })->with([
        'lowercase prefix' => ['tst-123'],
        'no number' => ['TST-'],
        'leading zero' => ['TST-0'],
        'topology-snapshot' => ['topology-snapshot'],
    ]);

    it('uses compact deterministic network names within the managed bridge limit', function () {
        $attempt = new AttemptId(str_repeat('a', 32));

        expect(TopologyTarget::topologySnapshot()->network())
            ->toBe('oe-topo-snap')
            ->and(TopologyTarget::feature('TST-123', $attempt)->network())
            ->toBe('oe-50fa1830b7de')
            ->and(TopologyTarget::feature('ORBIT-123456789', $attempt)->network())
            ->toBe('oe-83bc72a8f494')
            ->and(strlen(TopologyTarget::feature('ORBIT-123456789', $attempt)->network()))
            ->toBe(15);
    });

    it('keeps instance names readable and inside the Incus name limit', function () {
        $attempt = new AttemptId(str_repeat('a', 32));
        $maximumRecipe = new TopologyRecipe('maximum-name', [
            new TopologyNode(
                'a'.str_repeat('b', 22),
                TopologyRecipe::BASE_IMAGE,
                TopologyNodePurpose::Gateway,
                10,
                true,
                ['gateway', 'vpn'],
            ),
            new TopologyNode(
                'operator',
                TopologyRecipe::BASE_IMAGE,
                TopologyNodePurpose::Operator,
                11,
                true,
                ['app-dev'],
            ),
            new TopologyNode(
                'workload',
                TopologyRecipe::BASE_IMAGE,
                TopologyNodePurpose::Workload,
                12,
                false,
                ['app-prod'],
            ),
        ]);
        $maximumTarget = TopologyTarget::disposableCold('ORBITABCDE-123456789', $attempt, $maximumRecipe);

        expect(TopologyTarget::feature('TST-123', $attempt)->instance('app-dev'))
            ->toBe('orbit-e2e-tst-123-aaaaaaaa-app-dev')
            ->and(TopologyTarget::topologySnapshot()->instance('gateway'))
            ->toBe('orbit-e2e-topology-snapshot-gateway')
            ->and(strlen(TopologyTarget::feature('ORBIT-123456789', $attempt)->instance('app-prod')))
            ->toBeLessThanOrEqual(63)
            ->and(strlen($maximumTarget->instance('gateway')))
            ->toBe(63);
    });

    it('never shares resource identities between two attempts of one issue', function () {
        $first = TopologyTarget::feature('TST-123', new AttemptId(str_repeat('a', 32)));
        $second = TopologyTarget::feature('TST-123', new AttemptId(str_repeat('b', 32)));

        expect($first->network())
            ->not->toBe($second->network())->and($first->instance('gateway'))
            ->not->toBe($second->instance('gateway'))->and($first->mac('gateway'))
            ->not->toBe($second->mac('gateway'));
    });

    it('derives disposable identities from physical Nodes instead of assigned roles', function () {
        $target = TopologyTarget::disposableCold(
            'AUX-106',
            new AttemptId(str_repeat('a', 32)),
            TopologyRecipe::coldAcceptance(),
        );

        expect($target->network())->toBe('oe-a004e3f9e8dd');
        expect($target->instance('operator'))->toBe('orbit-e2e-aux-106-aaaaaaaa-operator');
        expect($target->instance('app-dev'))->toBe('orbit-e2e-aux-106-aaaaaaaa-operator');
        expect($target->instance('extra'))->toBe('orbit-e2e-aux-106-aaaaaaaa-extra');
        expect($target->mac('operator'))->toBe('00:16:3e:77:1f:b1');
        expect($target->mac('app-dev'))->toBe('00:16:3e:77:1f:b1');
        expect(TopologyTarget::ipv4For(2, $target->recipe->node('extra')->address))->toBe('10.232.2.13');
    });

    it('carries the attempt identity of a feature target only', function () {
        $attempt = new AttemptId(str_repeat('a', 32));

        expect(TopologyTarget::feature('TST-123', $attempt)->attempt)
            ->toEqual($attempt)
            ->and(TopologyTarget::feature('TST-123', $attempt)->isTopologySnapshot())
            ->toBeFalse()
            ->and(TopologyTarget::topologySnapshot()->attempt)
            ->toBeNull()
            ->and(TopologyTarget::topologySnapshot()->isTopologySnapshot())
            ->toBeTrue();
    });

    it('uses the proven deterministic topology and role MAC formula', function () {
        $target = TopologyTarget::feature('TST-123', new AttemptId(str_repeat('a', 32)));

        expect($target->mac('gateway'))
            ->toBe('00:16:3e:'.implode(':', str_split(substr(sha1($target->network().':gateway'), 0, 6), 2)))
            ->and(TopologyTarget::topologySnapshot()->mac('app-prod'))
            ->toBe('00:16:3e:'.implode(':', str_split(substr(sha1('oe-topo-snap:app-prod'), 0, 6), 2)));
    });

    it('matches the issue as one delimited branch token', function (string $branch, bool $matches) {
        expect(TopologyTarget::feature('TST-123', AttemptId::generate())->matchesBranch($branch))->toBe($matches);
    })->with([
        'prefixed feature branch' => ['feature/TST-123-build-topology', true],
        'lowercase worktree branch' => ['codex/tst-123', true],
        'larger issue number' => ['feature/TST-1234-build-topology', false],
        'issue-like suffix' => ['feature/TST-123A-build-topology', false],
        'unrelated issue' => ['feature/TST-124-build-topology', false],
    ]);

    it('resolves the retired topology snapshot target to the retired identity resources', function () {
        $retired = TopologyTarget::topologySnapshot(TopologySnapshotIdentity::retired());

        expect($retired->network())
            ->toBe('oe-standby')
            ->and($retired->instance('gateway'))
            ->toBe('orbit-e2e-standby-gateway')
            ->and($retired->mac('gateway'))
            ->toBe('00:16:3e:'.implode(':', str_split(substr(sha1('oe-standby:gateway'), 0, 6), 2)));
    });

    it('keeps the topology snapshot target unchanged when no identity is given', function () {
        expect(TopologyTarget::topologySnapshot()->network())
            ->toBe('oe-topo-snap')
            ->and(TopologyTarget::topologySnapshot()->requireTopologySnapshotIdentity())
            ->toEqual(TopologySnapshotIdentity::primary());
    });

    it('rejects a variable physical recipe for the fixed topology snapshot identity', function () {
        expect(fn () => TopologyTarget::topologySnapshot(recipe: TopologyRecipe::coldAcceptance()))
            ->toThrow(InvalidArgumentException::class, 'registered physical Node keys');
    });

    it('carries the topology snapshot identity of a topology snapshot target only', function () {
        $attempt = new AttemptId(str_repeat('a', 32));

        expect(TopologyTarget::topologySnapshot(TopologySnapshotIdentity::retired())->requireTopologySnapshotIdentity())
            ->toEqual(TopologySnapshotIdentity::retired())
            ->and(fn () => TopologyTarget::feature('TST-123', $attempt)->requireTopologySnapshotIdentity())
            ->toThrow(InvalidArgumentException::class);
    });
});
