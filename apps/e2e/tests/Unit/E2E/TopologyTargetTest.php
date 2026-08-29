<?php

declare(strict_types=1);

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

    it('uses compact deterministic network names', function () {
        expect(TopologyTarget::standby()->network())
            ->toBe('oe-standby')
            ->and(new TopologyTarget('NCK-123')->network())
            ->toBe('oe-b32d6c83af72')
            ->and(new TopologyTarget('ORBIT-123456789')->network())
            ->toBe('oe-f1eb5094419d')
            ->and(strlen(new TopologyTarget('ORBIT-123456789')->network()))
            ->toBe(15);
    });

    it('keeps instance names based on the target identity', function () {
        expect(new TopologyTarget('NCK-123')->instance('app-dev'))
            ->toBe('orbit-e2e-nck-123-app-dev')
            ->and(TopologyTarget::standby()->instance('gateway'))
            ->toBe('orbit-e2e-standby-gateway');
    });

    it('uses the proven deterministic topology and role MAC formula', function () {
        $target = new TopologyTarget('NCK-123');

        expect($target->mac('gateway'))
            ->toBe('00:16:3e:'.implode(':', str_split(substr(sha1($target->network().':gateway'), 0, 6), 2)))
            ->and(TopologyTarget::standby()->mac('app-prod'))
            ->toBe('00:16:3e:'.implode(':', str_split(substr(sha1('oe-standby:app-prod'), 0, 6), 2)));
    });

    it('matches the issue as one delimited branch token', function (string $branch, bool $matches) {
        expect(new TopologyTarget('NCK-123')->matchesBranch($branch))->toBe($matches);
    })->with([
        'prefixed feature branch' => ['feature/NCK-123-build-topology', true],
        'lowercase worktree branch' => ['codex/nck-123', true],
        'larger issue number' => ['feature/NCK-1234-build-topology', false],
        'issue-like suffix' => ['feature/NCK-123A-build-topology', false],
        'unrelated issue' => ['feature/NCK-124-build-topology', false],
    ]);
});
