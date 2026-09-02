<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationLock;
use App\E2E\State\StatePaths;
use App\E2E\TopologySnapshotRecoveryResolver;
use App\E2E\Value\OperationId;
use App\E2E\Value\TopologySnapshotIdentity;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
    Process::preventStrayProcesses();
    Process::fake(['*' => Process::result('[]')]);
});

/** @return array{TopologySnapshotRecoveryResolver, AtomicJsonStore, StatePaths} */
function recoveryResolverFixture(): array
{
    $paths = new StatePaths(temporaryPath('orbit-recovery-resolver-', 6));
    $state = new AtomicJsonStore($paths);
    $host = new IncusHost;

    return [
        new TopologySnapshotRecoveryResolver(
            $host,
            new IncusNetworkLifecycle($host),
            $state,
            $paths,
            new OperationLock($paths),
            new OperationId(str_repeat('a', 32)),
            TopologySnapshotIdentity::primary(),
        ),
        $state,
        $paths,
    ];
}

describe('TopologySnapshotRecoveryResolver', function (): void {
    it('uses the current identity when no retired state or resources exist', function (): void {
        [$resolver] = recoveryResolverFixture();

        expect($resolver->resolve()->source)->toBe('current');
    });

    it('uses the retired identity when only its state exists', function (): void {
        [$resolver, $state] = recoveryResolverFixture();
        $state->write('standby/promoted.json', ['schema' => 5]);

        expect($resolver->resolve()->source)->toBe('retired');
    });

    it('refuses mixed current and retired identities before recovery', function (): void {
        [$resolver, $state] = recoveryResolverFixture();
        $state->write('standby/promoted.json', ['schema' => 5]);
        $state->write('topology-snapshot/promoted.json', ['schema' => 5]);

        expect(fn () => $resolver->resolve())
            ->toThrow(RuntimeException::class, 'current and retired topology snapshot identities coexist');
    });

    it('resumes the retired identity from its retained exact inventory', function (): void {
        [$resolver, $state] = recoveryResolverFixture();
        $state->write('topology-snapshot/recovery.json', [
            'inventory' => [
                'instances' => ['orbit-e2e-standby-gateway' => []],
                'network' => null,
            ],
        ]);

        expect($resolver->resolve()->source)->toBe('retired');
    });

    it('returns to the current identity after retired migration completed', function (): void {
        [$resolver, $state] = recoveryResolverFixture();
        $state->write('topology-snapshot/recovery.json', [
            'phase' => 'construction_verified',
            'inventory' => [
                'instances' => ['orbit-e2e-standby-gateway' => []],
                'network' => null,
            ],
        ]);

        expect($resolver->resolve()->source)->toBe('current');
    });

    it('refuses a completed migration while retired state remains', function (): void {
        [$resolver, $state] = recoveryResolverFixture();
        $state->write('topology-snapshot/recovery.json', [
            'phase' => 'construction_verified',
            'inventory' => [
                'instances' => ['orbit-e2e-standby-gateway' => []],
                'network' => null,
            ],
        ]);
        $state->write('standby/promoted.json', ['schema' => 5]);

        expect(fn () => $resolver->resolve())
            ->toThrow(RuntimeException::class, 'Retired topology snapshot state remains after completed migration');
    });

    it('refuses mixed identities when a completed current recovery journal exists', function (): void {
        [$resolver, $state] = recoveryResolverFixture();
        $state->write('topology-snapshot/recovery.json', [
            'phase' => 'construction_verified',
            'inventory' => [
                'instances' => ['orbit-e2e-topology-snapshot-gateway' => []],
                'network' => null,
            ],
        ]);
        $state->write('topology-snapshot/promoted.json', ['schema' => 5]);
        $state->write('standby/promoted.json', ['schema' => 5]);

        expect(fn () => $resolver->resolve())
            ->toThrow(RuntimeException::class, 'current and retired topology snapshot identities coexist');
    });

    it('refuses a pre-rename recovery journal that must be completed with its original code', function (): void {
        [$resolver, $state] = recoveryResolverFixture();
        $state->write('standby/recovery.json', ['phase' => 'authorized']);

        expect(fn () => $resolver->resolve())
            ->toThrow(RuntimeException::class, 'pre-rename recovery journal');
    });

    it('accepts completed pre-rename recovery evidence for retired migration', function (): void {
        [$resolver, $state] = recoveryResolverFixture();
        $state->write('standby/recovery.json', ['phase' => 'construction_verified']);
        $state->write('standby/promoted.json', ['schema' => 5]);

        expect($resolver->resolve()->source)->toBe('retired');
    });

    it('refuses malformed pre-rename recovery evidence', function (): void {
        [$resolver, , $paths] = recoveryResolverFixture();
        file_put_contents($paths->ensureParent('standby/recovery.json'), '{');

        expect(fn () => $resolver->resolve())
            ->toThrow(RuntimeException::class, 'pre-rename recovery journal');
    });

    it('refuses a symbolic link as pre-rename recovery evidence', function (): void {
        [$resolver, $state, $paths] = recoveryResolverFixture();
        $target = temporaryFile('orbit-retired-recovery-');
        file_put_contents($target, json_encode(['phase' => 'construction_verified']));
        $state->write('standby/promoted.json', ['schema' => 5]);
        symlink($target, $paths->root().'/standby/recovery.json');

        expect(fn () => $resolver->resolve())
            ->toThrow(RuntimeException::class, 'pre-rename recovery journal');
    });

    it('refuses a retained inventory outside both exact identity sets', function (): void {
        [$resolver, $state] = recoveryResolverFixture();
        $state->write('topology-snapshot/recovery.json', [
            'inventory' => [
                'instances' => ['orbit-e2e-unknown-gateway' => []],
                'network' => null,
            ],
        ]);

        expect(fn () => $resolver->resolve())
            ->toThrow(RuntimeException::class, 'retained topology snapshot recovery identity is invalid');
    });
});
