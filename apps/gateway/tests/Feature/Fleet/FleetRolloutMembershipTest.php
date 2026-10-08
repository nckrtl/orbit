<?php

declare(strict_types=1);

use App\Domain\Fleet\FleetRolloutMembership;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use Tests\Support\Fleet\FleetFixtures;

describe('fleet rollout set', function (): void {
    it('holds active, managed Linux Nodes with a workload role, without the Gateway', function (): void {
        FleetFixtures::node('gateway', [RoleName::Gateway, RoleName::Vpn, RoleName::WebSocket]);
        FleetFixtures::node('vpn', [RoleName::Vpn]);
        FleetFixtures::node('beast', [RoleName::AppDev, RoleName::Metrics, RoleName::Database]);
        FleetFixtures::node('app-prod', [RoleName::AppProd]);
        FleetFixtures::node('services', [RoleName::AppDev, RoleName::Database, RoleName::WebSocket, RoleName::Analytics]);
        FleetFixtures::node('mini', platform: 'macos');
        FleetFixtures::node('polar');
        FleetFixtures::node('unpinned', [RoleName::AppDev], managed: false);
        FleetFixtures::node('broken', [RoleName::AppDev], status: LifecycleStatus::Failed);
        $membership = app(FleetRolloutMembership::class);

        expect(array_map(static fn (Node $node): string => $node->name, $membership->members()))->toBe(['vpn', 'beast', 'services', 'app-prod'])
            ->and(Node::query()->with('roles')->orderBy('id')->get()->mapWithKeys(static fn (Node $node): array => [$node->name => $membership->exclusion($node)])->all())
            ->toBe([
                'gateway' => 'gateway',
                'vpn' => null,
                'beast' => null,
                'app-prod' => null,
                'services' => null,
                'mini' => 'platform',
                'polar' => 'roleless',
                'unpinned' => 'unmanaged',
                'broken' => 'inactive',
            ]);
    });

    it('leaves out a disposable task sandbox, though it is an active, managed app-dev Node', function (): void {
        $dev = FleetFixtures::node('dev', [RoleName::AppDev]);
        $sandbox = FleetFixtures::sandbox();
        $membership = app(FleetRolloutMembership::class);

        expect(array_map(static fn (Node $node): string => $node->name, $membership->members()))->toBe(['dev'])
            ->and($membership->includes($sandbox))->toBeFalse()
            ->and($membership->exclusion($sandbox))->toBe('sandbox')
            ->and($membership->exclusion($dev))->toBeNull();
    });

    it('puts a Node in the latest group of its roles', function (): void {
        $membership = app(FleetRolloutMembership::class);

        expect($membership->group(FleetFixtures::node('dev', [RoleName::AppDev])))->toBe(1)
            ->and($membership->group(FleetFixtures::node('metrics', [RoleName::AppDev, RoleName::Metrics])))->toBe(2)
            ->and($membership->group(FleetFixtures::node('db', [RoleName::AppDev, RoleName::Database])))->toBe(3)
            ->and($membership->group(FleetFixtures::node('prod', [RoleName::AppProd, RoleName::Metrics])))->toBe(4);
    });
});
