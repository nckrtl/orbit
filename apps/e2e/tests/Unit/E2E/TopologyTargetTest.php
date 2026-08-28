<?php

declare(strict_types=1);

use App\E2E\Value\TopologyTarget;

describe('TopologyTarget', function () {
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
});
