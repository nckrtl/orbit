<?php

declare(strict_types=1);

use App\Actions\Nodes\RemoveNodeRoleAction;
use App\Domain\AppDev\AppDevRuntimeConverger;
use App\Domain\AppProd\AppProdRuntimeConverger;
use App\Domain\Instances\CertificateMode;
use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Domain\Nodes\NodeRoleDependencyInspector;
use App\Domain\Nodes\NodeRoleDependencySet;
use App\Domain\Nodes\NodeRoleDependentCleaner;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\NodeRoleToolIntentGuard;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\NodeSideResidue;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerScopeLock;
use App\Domain\Tools\ToolStatus;
use App\Infrastructure\Nodes\NativeNodeRoleDependentCleaner;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Process;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** @mago-expect lint:halstead The removal group keeps ordered recovery and failure state observable. */
describe(RemoveNodeRoleAction::class, function (): void {
    it('rejects app role removal before mutation when a manager scope is busy', function (): void {
        [$node, $assignment] = removal_role_fixture();
        $cleaner = new RemovalCleanerFake;
        $baseline = new RemovalBaselineFake;
        $scope = Cache::lock("orbit:tool-manager:{$node->id}:vp", 3_600);
        expect($scope->get())->toBeTrue();

        try {
            expect(fn () => removal_action(
                new RemovalInspectorFake(new NodeRoleDependencySet([], [], [], [])),
                $cleaner,
                $baseline,
            )->execute($node, RoleName::AppDev, force: true))
                ->toThrow(function (NodeRoleOperationException $exception): void {
                    expect($exception->errorCode)
                        ->toBe('node_role.remove_failed')
                        ->and($exception->underlyingErrorCode)
                        ->toBe('node_role.tool_manager_locked');
                });
        } finally {
            $scope->release();
        }

        expect($assignment->refresh()->status)
            ->toBe(LifecycleStatus::Active)
            ->and($cleaner->calls)
            ->toBe(0)
            ->and($baseline->calls)
            ->toBe(0);
    });

    it('locks app role removal even when another app role row exists', function (LifecycleStatus $otherStatus): void {
        [$node, $assignment] = removal_role_fixture();
        $node->roles()->create([
            'role' => RoleName::AppProd,
            'status' => $otherStatus,
            'failed_step' => $otherStatus === LifecycleStatus::Failed ? 'converge:baseline' : null,
            'error_code' => $otherStatus === LifecycleStatus::Failed ? 'app-prod.baseline_failed' : null,
        ]);
        $cleaner = new RemovalCleanerFake;
        $baseline = new RemovalBaselineFake;
        $scope = Cache::lock("orbit:tool-manager:{$node->id}:vp", 3_600);
        expect($scope->get())->toBeTrue();

        try {
            expect(fn () => removal_action(
                new RemovalInspectorFake(new NodeRoleDependencySet([], [], [], [])),
                $cleaner,
                $baseline,
            )->execute($node, RoleName::AppDev, force: true))
                ->toThrow(NodeRoleOperationException::class);
        } finally {
            $scope->release();
        }

        expect($assignment->refresh()->status)
            ->toBe(LifecycleStatus::Active)
            ->and($cleaner->calls)
            ->toBe(0)
            ->and($baseline->calls)
            ->toBe(0);
    })->with([
        'unsupported failed counterpart' => LifecycleStatus::Failed,
        'supported active counterpart' => LifecycleStatus::Active,
        'supported provisioning counterpart' => LifecycleStatus::Provisioning,
    ]);

    it('releases VP when Composer contention blocks app role removal', function (): void {
        [$node, $assignment] = removal_role_fixture();
        $composer = Cache::lock("orbit:tool-manager:{$node->id}:composer", 3_600);
        expect($composer->get())->toBeTrue();

        try {
            expect(fn () => removal_action(
                new RemovalInspectorFake(new NodeRoleDependencySet([], [], [], [])),
                new RemovalCleanerFake,
                new RemovalBaselineFake,
            )->execute($node, RoleName::AppDev, force: true))
                ->toThrow(NodeRoleOperationException::class);
        } finally {
            $composer->release();
        }

        $vp = Cache::lock("orbit:tool-manager:{$node->id}:vp", 3_600);
        expect($vp->get())
            ->toBeTrue()
            ->and($assignment->refresh()->status)
            ->toBe(LifecycleStatus::Active);
        $vp->release();
    });
    it('always returns a no-force preview without mutation even when dependents are empty', function (): void {
        [$node, $assignment] = removal_role_fixture();
        $inspector = new RemovalInspectorFake(new NodeRoleDependencySet([], [], [], []));
        $cleaner = new RemovalCleanerFake;
        $baseline = new RemovalBaselineFake;
        $action = removal_action($inspector, $cleaner, $baseline);

        expect(fn () => $action->execute($node, RoleName::AppDev, force: false, purgeData: false))
            ->toThrow(function (NodeRoleValidationException $exception): void {
                expect($exception->getMessage())
                    ->toBe('Use --force to remove this node role.')
                    ->and($exception->details)
                    ->toBe([
                        'field' => 'force',
                        'reason' => 'destructive_consent_required',
                        'role' => 'app-dev',
                        'dependents' => [],
                    ]);
            });

        expect($assignment->refresh()->status)
            ->toBe(LifecycleStatus::Active)
            ->and($cleaner->calls)
            ->toBe(0)
            ->and($baseline->calls)
            ->toBe(0);
    });

    it('includes sorted manager retirement summaries in the last app role no-force preview', function (): void {
        [$node] = removal_role_fixture();
        $node->toolManagers()->create([
            'name' => ToolManagerName::Vp,
            'status' => LifecycleStatus::Active,
        ]);
        $node->toolManagers()->create([
            'name' => ToolManagerName::Composer,
            'status' => LifecycleStatus::Active,
        ]);
        $action = removal_action(
            new RemovalInspectorFake(new NodeRoleDependencySet([], [], [], [])),
            new RemovalCleanerFake,
            new RemovalBaselineFake,
        );

        expect(fn () => $action->execute($node, RoleName::AppDev, force: false))
            ->toThrow(function (NodeRoleValidationException $exception): void {
                expect($exception->details['dependents'])->toBe([
                    'Composer Tool manager will become unavailable',
                    'VP Tool manager will become unavailable',
                ]);
            });

        $removed = $action->execute($node, RoleName::AppDev, force: true);

        expect($removed->dependencies->summaries)->toBe([
            'Composer Tool manager will become unavailable',
            'VP Tool manager will become unavailable',
        ]);
    });

    it('omits manager retirement summaries while another supported app role remains', function (): void {
        [$node] = removal_role_fixture();
        $node->roles()->create([
            'role' => RoleName::AppProd,
            'status' => LifecycleStatus::Provisioning,
        ]);
        $node->toolManagers()->create([
            'name' => ToolManagerName::Composer,
            'status' => LifecycleStatus::Active,
        ]);
        $action = removal_action(
            new RemovalInspectorFake(new NodeRoleDependencySet([], [], [], [])),
            new RemovalCleanerFake,
            new RemovalBaselineFake,
        );

        expect(fn () => $action->execute($node, RoleName::AppDev, force: false))
            ->toThrow(function (NodeRoleValidationException $exception): void {
                expect($exception->details['dependents'])->toBeEmpty();
            });
    });

    it('rejects removal of the last active app role with a sorted bounded Tool intent preview', function (): void {
        [$node, $assignment] = removal_role_fixture();
        $composer = $node->toolManagers()->create([
            'name' => ToolManagerName::Composer,
            'status' => LifecycleStatus::Active,
        ]);
        $vp = $node->toolManagers()->create([
            'name' => ToolManagerName::Vp,
            'status' => LifecycleStatus::Active,
        ]);

        foreach (range(start: 12, end: 1) as $number) {
            $manager = ($number % 2) === 0 ? $vp : $composer;
            $node->tools()->create([
                'tool_manager_id' => $manager->id,
                'package' => sprintf('package-%02d', $number),
                'protected' => false,
                'status' => ToolStatus::Installed,
                'installed_version' => '2.4.1',
            ]);
        }
        $cleaner = new RemovalCleanerFake;
        $baseline = new RemovalBaselineFake;
        $guard = app(NodeRoleToolIntentGuard::class);
        $action = new RemoveNodeRoleAction(
            new RemovalInspectorFake(new NodeRoleDependencySet([], [], [], [])),
            $cleaner,
            $baseline,
            app(\App\Domain\Nodes\RoleRegistry::class),
            $guard,
            app(ToolManagerScopeLock::class),
            new RemovalReachabilityFake(null),
            new NodeSideResidue,
        );

        expect($guard->preview($node, RoleName::AppDev))->toBe([
            'composer:package-01',
            'composer:package-03',
            'composer:package-05',
            'composer:package-07',
            'composer:package-09',
            'composer:package-11',
            'vp:package-02',
            'vp:package-04',
            'vp:package-06',
            'vp:package-08',
        ]);

        expect(fn () => $action->execute($node, RoleName::AppDev, force: true))
            ->toThrow(function (NodeRoleValidationException $exception): void {
                expect($exception->getMessage())
                    ->toBe('Remove app-scoped Tools before removing the last active app role.')
                    ->and($exception->details)
                    ->toBe([
                        'field' => 'role',
                        'reason' => 'tool_removal_required',
                        'role' => 'app-dev',
                        'tools' => [
                            'composer:package-01',
                            'composer:package-03',
                            'composer:package-05',
                            'composer:package-07',
                            'composer:package-09',
                            'composer:package-11',
                            'vp:package-02',
                            'vp:package-04',
                            'vp:package-06',
                            'vp:package-08',
                        ],
                    ]);
            });

        expect($assignment->refresh()->status)
            ->toBe(LifecycleStatus::Active)
            ->and($cleaner->calls)
            ->toBe(0)
            ->and($baseline->calls)
            ->toBe(0);
    });

    it('allows removal of the last active app role when app-scoped Tool intent is protected', function (): void {
        [$node, $assignment, $dependencies] = removal_role_fixture(withDependents: true);
        $manager = $node->toolManagers()->create([
            'name' => ToolManagerName::Vp,
            'status' => LifecycleStatus::Active,
        ]);
        $tool = $node->tools()->create([
            'tool_manager_id' => $manager->id,
            'package' => '@orbit/protected-runtime',
            'protected' => true,
            'status' => ToolStatus::Installed,
            'installed_version' => '2.4.1',
        ]);
        $action = new RemoveNodeRoleAction(
            new RemovalInspectorFake($dependencies),
            new RemovalCleanerFake,
            new RemovalBaselineFake,
            app(\App\Domain\Nodes\RoleRegistry::class),
            app(\App\Domain\Nodes\NodeRoleToolIntentGuard::class),
            app(ToolManagerScopeLock::class),
            new RemovalReachabilityFake(null),
            new NodeSideResidue,
        );

        $action->execute($node, RoleName::AppDev, force: true);

        expect(NodeRole::query()->whereKey($assignment->id)->exists())
            ->toBeFalse()
            ->and($tool->refresh()->status)
            ->toBe(ToolStatus::Installed);
    });

    it('allows removal with APT Tool intent and blocks failed VP Tool intent', function (): void {
        [$aptNode, $aptAssignment] = removal_role_fixture();
        removal_tool(node: $aptNode, managerName: ToolManagerName::Apt, package: 'jq', toolStatus: ToolStatus::Failed);

        removal_action(
            new RemovalInspectorFake(new NodeRoleDependencySet([], [], [], [])),
            new RemovalCleanerFake,
            new RemovalBaselineFake,
        )->execute($aptNode, RoleName::AppDev, force: true);

        expect(NodeRole::query()->whereKey($aptAssignment->id)->exists())->toBeFalse();

        [$vpNode, $vpAssignment] = removal_role_fixture();
        removal_tool(
            node: $vpNode,
            managerName: ToolManagerName::Vp,
            package: '@openai/codex',
            toolStatus: ToolStatus::Failed,
        );

        expect(fn () => removal_action(
            new RemovalInspectorFake(new NodeRoleDependencySet([], [], [], [])),
            new RemovalCleanerFake,
            new RemovalBaselineFake,
        )->execute($vpNode, RoleName::AppDev, force: true))
            ->toThrow(NodeRoleValidationException::class);

        expect($vpAssignment->refresh()->status)->toBe(LifecycleStatus::Active);
    });

    it('allows app role removal while another active app role remains', function (): void {
        [$node, $assignment, $dependencies] = removal_role_fixture(withDependents: true);
        $node->roles()->create([
            'role' => RoleName::AppProd,
            'status' => LifecycleStatus::Active,
        ]);
        $manager = $node->toolManagers()->create([
            'name' => ToolManagerName::Composer,
            'status' => LifecycleStatus::Active,
        ]);
        $tool = $node->tools()->create([
            'tool_manager_id' => $manager->id,
            'package' => 'laravel/installer',
            'protected' => false,
            'status' => ToolStatus::Installed,
            'installed_version' => '2.4.1',
        ]);
        $action = new RemoveNodeRoleAction(
            new RemovalInspectorFake($dependencies),
            new RemovalCleanerFake,
            new RemovalBaselineFake,
            app(\App\Domain\Nodes\RoleRegistry::class),
            app(\App\Domain\Nodes\NodeRoleToolIntentGuard::class),
            app(ToolManagerScopeLock::class),
            new RemovalReachabilityFake(null),
            new NodeSideResidue,
        );

        $action->execute($node, RoleName::AppDev, force: true);

        expect(NodeRole::query()->whereKey($assignment->id)->exists())
            ->toBeFalse()
            ->and($node->roles()->where('role', RoleName::AppProd)->exists())
            ->toBeTrue()
            ->and($tool->refresh()->status)
            ->toBe(ToolStatus::Installed);
    });

    it('allows app role removal and keeps managers supported while another app role is provisioning', function (): void {
        [$node, $assignment] = removal_role_fixture();
        $node->roles()->create([
            'role' => RoleName::AppProd,
            'status' => LifecycleStatus::Provisioning,
        ]);
        [$manager, $tool] = removal_tool(
            node: $node,
            managerName: ToolManagerName::Composer,
            package: 'laravel/installer',
        );

        removal_action(
            new RemovalInspectorFake(new NodeRoleDependencySet([], [], [], [])),
            new RemovalCleanerFake,
            new RemovalBaselineFake,
        )->execute($node, RoleName::AppDev, force: true);

        expect(NodeRole::query()->whereKey($assignment->id)->exists())
            ->toBeFalse()
            ->and($manager->refresh()->status)
            ->toBe(LifecycleStatus::Active)
            ->and($tool->refresh()->status)
            ->toBe(ToolStatus::Installed);
    });

    it('cleans dependents and baseline outside short transactions before deleting records', function (): void {
        [$node, $assignment, $dependencies] = removal_role_fixture(withDependents: true);
        $inspector = new RemovalInspectorFake($dependencies);
        $events = [];
        $cleaner = new RemovalCleanerFake;
        $cleaner->events = &$events;
        $baseline = new RemovalBaselineFake;
        $baseline->events = &$events;
        $action = removal_action($inspector, $cleaner, $baseline);
        $ambientTransactionLevel = DB::transactionLevel();

        $removed = $action->execute($node, RoleName::AppDev, force: true, purgeData: true);

        expect($removed->dependencies)
            ->toBe($dependencies)
            ->and($events)
            ->toBe([
                "clean:{$ambientTransactionLevel}",
                "baseline:1:{$ambientTransactionLevel}",
            ])
            ->and($cleaner->observedStatuses)
            ->toBe([
                LifecycleStatus::Removing,
                LifecycleStatus::Removing,
                LifecycleStatus::Removing,
            ])
            ->and(NodeRole::query()->whereKey($assignment->id)->exists())
            ->toBeFalse();

        expect(removal_dependency_rows_exist($dependencies))->toBeFalse();
    });

    it('retires unsupported app managers while preserving every Tool and installed version', function (): void {
        [$node, $assignment] = removal_role_fixture();
        [$aptManager, $aptTool] = removal_tool(
            node: $node,
            managerName: ToolManagerName::Apt,
            package: 'jq',
            managerVersion: '3.0.0',
        );
        [$vpManager, $vpTool] = removal_tool(
            node: $node,
            managerName: ToolManagerName::Vp,
            package: '@openai/codex',
            protected: true,
            managerVersion: '1.2.3',
        );
        [$composerManager, $composerTool] = removal_tool(
            node: $node,
            managerName: ToolManagerName::Composer,
            package: 'laravel/installer',
            protected: true,
            managerVersion: '2.8.1',
        );

        removal_action(
            new RemovalInspectorFake(new NodeRoleDependencySet([], [], [], [])),
            new RemovalCleanerFake,
            new RemovalBaselineFake,
        )->execute($node, RoleName::AppDev, force: true);

        expect(NodeRole::query()->whereKey($assignment->id)->exists())
            ->toBeFalse()
            ->and($node->tools()->count())
            ->toBe(3)
            ->and($node->toolManagers()->count())
            ->toBe(3)
            ->and($aptManager->refresh()->status)
            ->toBe(LifecycleStatus::Active)
            ->and($vpManager->refresh()->status)
            ->toBe(LifecycleStatus::Failed)
            ->and($vpManager->failed_step)
            ->toBe('app-role')
            ->and($vpManager->error_code)
            ->toBe('tool_manager.app_role_required')
            ->and($vpManager->installed_version)
            ->toBe('1.2.3')
            ->and($composerManager->refresh()->status)
            ->toBe(LifecycleStatus::Failed)
            ->and($composerManager->installed_version)
            ->toBe('2.8.1')
            ->and($aptTool->refresh()->installed_version)
            ->toBe('2.4.1')
            ->and($vpTool->refresh()->installed_version)
            ->toBe('2.4.1')
            ->and($composerTool->refresh()->installed_version)
            ->toBe('2.4.1');
    });

    it('retires app managers when another app role is not supported', function (LifecycleStatus $status): void {
        [$node] = removal_role_fixture();
        $node->roles()->create([
            'role' => RoleName::AppProd,
            'status' => $status,
        ]);
        [$manager] = removal_tool(
            node: $node,
            managerName: ToolManagerName::Vp,
            package: '@openai/codex',
            protected: true,
        );

        removal_action(
            new RemovalInspectorFake(new NodeRoleDependencySet([], [], [], [])),
            new RemovalCleanerFake,
            new RemovalBaselineFake,
        )->execute($node, RoleName::AppDev, force: true);

        expect($manager->refresh()->status)
            ->toBe(LifecycleStatus::Failed)
            ->and($manager->failed_step)
            ->toBe('app-role');
    })->with([
        'failed assignment' => LifecycleStatus::Failed,
        'removing assignment' => LifecycleStatus::Removing,
    ]);

    it('rechecks Tool intent under the claim lock before remote mutation', function (): void {
        [$node, $assignment] = removal_role_fixture();
        $cleaner = new RemovalCleanerFake;
        $baseline = new RemovalBaselineFake;
        $guard = new RemovalToolIntentGuardFake;
        $guard->onAssert = function (int $call) use ($node): void {
            if ($call === 2) {
                expect(DB::transactionLevel())->toBeGreaterThan(0);
                removal_tool(node: $node, managerName: ToolManagerName::Composer, package: 'laravel/installer');
                throw new NodeRoleValidationException('Tool intent appeared under lock.');
            }
        };
        $action = removal_action(
            new RemovalInspectorFake(new NodeRoleDependencySet([], [], [], [])),
            $cleaner,
            $baseline,
            $guard,
        );

        expect(fn () => $action->execute($node, RoleName::AppDev, force: true))
            ->toThrow(NodeRoleValidationException::class, 'Tool intent appeared under lock.');

        expect($assignment->refresh()->status)
            ->toBe(LifecycleStatus::Active)
            ->and($cleaner->calls)
            ->toBe(0)
            ->and($baseline->calls)
            ->toBe(0);
    });

    it('rolls back finalization when Tool intent appears after role deletion', function (): void {
        [$node, $assignment] = removal_role_fixture();
        $guard = new RemovalToolIntentGuardFake;
        $roleExistsDuringFinalCheck = null;
        $guard->onAssert = function (int $call) use ($node, &$roleExistsDuringFinalCheck): void {
            if ($call === 3) {
                $roleExistsDuringFinalCheck = $node->roles()->where('role', RoleName::AppDev)->exists();
                removal_tool(node: $node, managerName: ToolManagerName::Vp, package: '@openai/codex');
                throw new NodeRoleValidationException('Tool intent raced finalization.');
            }
        };
        $action = removal_action(
            new RemovalInspectorFake(new NodeRoleDependencySet([], [], [], [])),
            new RemovalCleanerFake,
            new RemovalBaselineFake,
            $guard,
        );

        expect(fn () => $action->execute($node, RoleName::AppDev, force: true))
            ->toThrow(NodeRoleOperationException::class);

        expect($roleExistsDuringFinalCheck)
            ->toBeFalse()
            ->and($assignment->refresh()->status)
            ->toBe(LifecycleStatus::Failed)
            ->and($assignment->failed_step)
            ->toBe('remove:dependency-race')
            ->and($node->tools()->exists())
            ->toBeFalse();
    });

    it('keeps every row retryable when a remote stage fails', function (string $step): void {
        [$node, $assignment, $dependencies] = removal_role_fixture(withDependents: true);
        [$manager] = removal_tool(
            node: $node,
            managerName: ToolManagerName::Vp,
            package: '@openai/codex',
            protected: true,
        );
        $inspector = new RemovalInspectorFake($dependencies);
        $cleaner = new RemovalCleanerFake;
        $baseline = new RemovalBaselineFake;

        if ($step === 'baseline') {
            $baseline->failure = new \RuntimeException('baseline failed');
        }

        if ($step !== 'baseline') {
            $cleaner->failure = new \App\Domain\AppDev\RuntimeConvergenceException(
                step: $step,
                errorCode: "cleanup.{$step}_failed",
                message: "{$step} failed",
            );
        }

        $action = removal_action($inspector, $cleaner, $baseline);

        expect(fn () => $action->execute($node, RoleName::AppDev, force: true, purgeData: false))
            ->toThrow(NodeRoleOperationException::class);

        expect($assignment->refresh()->status)
            ->toBe(LifecycleStatus::Failed)
            ->and($assignment->failed_step)
            ->toBe("remove:{$step}")
            ->and($manager->refresh()->status)
            ->toBe(LifecycleStatus::Active)
            ->and(removal_dependency_rows_exist($dependencies))
            ->toBeTrue();

        $cleaner->failure = null;
        $baseline->failure = null;
        $removed = $action->execute($node, RoleName::AppDev, force: true, purgeData: false);

        expect($removed->dependencies->instanceIds)
            ->toBe($dependencies->instanceIds)
            ->and($removed->dependencies->workspaceIds)
            ->toBe($dependencies->workspaceIds)
            ->and($removed->dependencies->processIds)
            ->toBe($dependencies->processIds)
            ->and($removed->degradation)
            ->toBeNull()
            ->and($removed->retained)
            ->toBe([])
            ->and($removed->dependencies->summaries)
            ->toBe([
                ...$dependencies->summaries,
                'VP Tool manager will become unavailable',
            ])
            ->and(NodeRole::query()->whereKey($assignment->id)->exists())
            ->toBeFalse()
            ->and(removal_dependency_rows_exist($dependencies))
            ->toBeFalse();
    })->with([
        'process runtime' => 'process-runtime',
        'workspace publication' => 'workspace-runtime',
        'instance publication' => 'instance-runtime',
        'role baseline' => 'baseline',
    ]);

    it('stops finalization when a new dependent appears and removes it on retry', function (): void {
        [$node, $assignment, $dependencies] = removal_role_fixture(withDependents: true);
        $inspector = app(NodeRoleDependencyInspector::class);
        $cleaner = new RemovalCleanerFake;
        $cleaner->afterClean = function () use ($dependencies): void {
            $instance = \App\Models\Instance::query()->findOrFail($dependencies->instanceIds[0]);
            removal_process(owner: $instance, name: 'late-process', status: LifecycleStatus::Active);
        };
        $baseline = new RemovalBaselineFake;
        $action = removal_action($inspector, $cleaner, $baseline);

        expect(fn () => $action->execute($node, RoleName::AppDev, force: true, purgeData: false))
            ->toThrow(NodeRoleOperationException::class);

        expect($assignment->refresh()->failed_step)
            ->toBe('remove:dependency-race')
            ->and(removal_dependency_rows_exist($dependencies))
            ->toBeTrue();

        $cleaner->afterClean = null;
        $action->execute($node, RoleName::AppDev, force: true, purgeData: false);

        expect($node->roles()->where('role', RoleName::AppDev->value)->exists())
            ->toBeFalse()
            ->and($node->instances()->exists())
            ->toBeFalse();
    });

    it('records the exact failure on the dependent that could not be cleaned', function (string $stage): void {
        [, , $dependencies] = removal_role_fixture(withDependents: true);
        Process::query()->whereIn('id', $dependencies->processIds)->update(['status' => LifecycleStatus::Removing]);
        Workspace::query()->whereIn('id', $dependencies->workspaceIds)->update(['status' => LifecycleStatus::Removing]);
        Instance::query()->whereIn('id', $dependencies->instanceIds)->update(['status' => LifecycleStatus::Removing]);
        $processes = Mockery::mock(ProcessRuntimeManager::class);
        $processes
            ->shouldReceive('remove')
            ->once()
            ->andReturnUsing(function () use ($stage): void {
                if ($stage === 'process-runtime') {
                    throw new ProcessOperationException('stop', 'process.stop_failed', 'stop failed');
                }
            });
        $appDev = Mockery::mock(AppDevRuntimeConverger::class);
        $appDev
            ->shouldReceive('unpublishWorkspace')
            ->times($stage === 'process-runtime' ? 0 : 1)
            ->andReturnUsing(function () use ($stage): void {
                if ($stage === 'workspace-runtime') {
                    throw new \App\Domain\AppDev\RuntimeConvergenceException(
                        'private-dns',
                        'app-dev.dns_config_failed',
                        'DNS failed',
                    );
                }
            });
        $appDev
            ->shouldReceive('unpublishInstance')
            ->times($stage === 'instance-runtime' ? 1 : 0)
            ->andThrow(new \App\Domain\AppDev\RuntimeConvergenceException(
                'certificate',
                'app-dev.certificate_remove_failed',
                'Certificate failed',
            ));
        $cleaner = new NativeNodeRoleDependentCleaner(
            processes: $processes,
            appDev: $appDev,
            appProd: Mockery::mock(AppProdRuntimeConverger::class),
        );

        expect(fn () => $cleaner->clean($dependencies))->toThrow(NodeRoleOperationException::class);

        $failed = match ($stage) {
            'process-runtime' => Process::query()->findOrFail($dependencies->processIds[0]),
            'workspace-runtime' => Workspace::query()->findOrFail($dependencies->workspaceIds[0]),
            'instance-runtime' => Instance::query()->findOrFail($dependencies->instanceIds[0]),
        };
        $expected = match ($stage) {
            'process-runtime' => ['stop', 'process.stop_failed'],
            'workspace-runtime' => ['private-dns', 'app-dev.dns_config_failed'],
            'instance-runtime' => ['certificate', 'app-dev.certificate_remove_failed'],
        };

        expect($failed->status)
            ->toBe(LifecycleStatus::Failed)
            ->and($failed->failed_step)
            ->toBe($expected[0])
            ->and($failed->error_code)
            ->toBe($expected[1]);
    })->with(['process-runtime', 'workspace-runtime', 'instance-runtime']);

    it('sheds a role from an unreachable node without attempting anything on it', function (): void {
        [$node, $assignment, $dependencies] = removal_role_fixture(withDependents: true);
        $inspector = new RemovalInspectorFake($dependencies);
        $cleaner = new RemovalCleanerFake;
        $baseline = new RemovalBaselineFake;
        $probe = new RemovalReachabilityFake(ExporterDegradationReason::Unreachable);
        $action = removal_action($inspector, $cleaner, $baseline, reachability: $probe);

        $removed = $action->execute($node, RoleName::AppDev, force: true, purgeData: false, offline: true);

        expect($probe->calls)
            ->toBe(1)
            ->and($cleaner->calls)
            ->toBe(0)
            ->and($baseline->events)
            ->toBe(['baseline-unreachable:'.DB::transactionLevel()])
            ->and($removed->degradation)
            ->toBe(ExporterDegradationReason::Unreachable)
            ->and($removed->retained)
            ->toContain('Caddy site configuration and certificates for the app-dev role')
            // The node keeps its registration, so the fleet still owns its
            // exporter; naming it would send the operator to wipe live state.
            ->not
            ->toContain('Metrics node exporter package, its Orbit systemd drop-in and its firewall rule for port 9100')
            ->and(NodeRole::query()->whereKey($assignment->id)->exists())
            ->toBeFalse()
            ->and(removal_dependency_rows_exist($dependencies))
            ->toBeFalse();
    });

    it('keeps a reachable node fail-closed even when the offline claim is made', function (): void {
        [$node, $assignment, $dependencies] = removal_role_fixture(withDependents: true);
        $cleaner = new RemovalCleanerFake;
        $cleaner->failure = new \App\Domain\AppDev\RuntimeConvergenceException(
            step: 'instance-runtime',
            errorCode: 'cleanup.instance-runtime_failed',
            message: 'instance-runtime failed',
        );
        $probe = new RemovalReachabilityFake(null);
        $action = removal_action(
            new RemovalInspectorFake($dependencies),
            $cleaner,
            new RemovalBaselineFake,
            reachability: $probe,
        );

        expect(fn () => $action->execute($node, RoleName::AppDev, force: true, purgeData: false, offline: true))
            ->toThrow(NodeRoleOperationException::class);

        expect($probe->calls)
            ->toBe(1)
            ->and($cleaner->calls)
            ->toBe(1)
            ->and($assignment->refresh()->status)
            ->toBe(LifecycleStatus::Failed)
            ->and($assignment->failed_step)
            ->toBe('remove:instance-runtime')
            ->and(removal_dependency_rows_exist($dependencies))
            ->toBeTrue();
    });

    it('names the offline flag on a node-side teardown failure', function (): void {
        [$node, , $dependencies] = removal_role_fixture(withDependents: true);
        $cleaner = new RemovalCleanerFake;
        $cleaner->failure = new \App\Domain\AppDev\RuntimeConvergenceException(
            step: 'instance-runtime',
            errorCode: 'cleanup.instance-runtime_failed',
            message: 'instance-runtime failed',
        );
        $action = removal_action(new RemovalInspectorFake($dependencies), $cleaner, new RemovalBaselineFake);

        expect(fn () => $action->execute($node, RoleName::AppDev, force: true, purgeData: false))
            ->toThrow(
                NodeRoleOperationException::class,
                "instance-runtime failed Retry with --offline if node [{$node->name}] is unreachable.",
            );
    });

    it('still fails closed when the Gateway side cannot be converged for an unreachable node', function (): void {
        [$node, $assignment, $dependencies] = removal_role_fixture(withDependents: true);
        $baseline = new RemovalBaselineFake;
        $baseline->failure = new \RuntimeException('gateway projection failed');
        $action = removal_action(
            new RemovalInspectorFake($dependencies),
            new RemovalCleanerFake,
            $baseline,
            reachability: new RemovalReachabilityFake(ExporterDegradationReason::Unreachable),
        );

        expect(fn () => $action->execute($node, RoleName::AppDev, force: true, purgeData: false, offline: true))
            ->toThrow(NodeRoleOperationException::class);

        expect($assignment->refresh()->status)
            ->toBe(LifecycleStatus::Failed)
            ->and($assignment->failed_step)
            ->toBe('remove:baseline')
            ->and(removal_dependency_rows_exist($dependencies))
            ->toBeTrue();
    });

    it('never probes reachability without the offline claim', function (): void {
        [$node, , $dependencies] = removal_role_fixture(withDependents: true);
        $probe = new RemovalReachabilityFake(ExporterDegradationReason::Unreachable);
        $action = removal_action(
            new RemovalInspectorFake($dependencies),
            new RemovalCleanerFake,
            new RemovalBaselineFake,
            reachability: $probe,
        );

        $removed = $action->execute($node, RoleName::AppDev, force: true, purgeData: false);

        expect($probe->calls)
            ->toBe(0)
            ->and($removed->degradation)
            ->toBeNull()
            ->and($removed->retained)
            ->toBe([]);
    });
});

/** @mago-expect lint:excessive-parameter-list The fixture mirrors the action's explicit collaborator list. */
function removal_action(
    NodeRoleDependencyInspector $inspector,
    NodeRoleDependentCleaner $cleaner,
    RoleBaselineConverger $baseline,
    ?NodeRoleToolIntentGuard $toolIntentGuard = null,
    ?NodeReachabilityProbe $reachability = null,
): RemoveNodeRoleAction {
    return new RemoveNodeRoleAction(
        $inspector,
        $cleaner,
        $baseline,
        app(\App\Domain\Nodes\RoleRegistry::class),
        $toolIntentGuard ?? app(NodeRoleToolIntentGuard::class),
        app(ToolManagerScopeLock::class),
        $reachability ?? new RemovalReachabilityFake(null),
        new NodeSideResidue,
    );
}

/** @mago-expect lint:single-class-per-file Test-local fakes keep the removal collaborators visible to this suite. */
final class RemovalReachabilityFake implements NodeReachabilityProbe
{
    public int $calls = 0;

    public function __construct(
        private readonly ?ExporterDegradationReason $reason,
    ) {}

    public function degradation(Node $node): ?ExporterDegradationReason
    {
        $this->calls++;

        return $this->reason;
    }
}

/**
 * @return array{\App\Models\ToolManagerRecord, \App\Models\Tool}
 * @mago-expect lint:excessive-parameter-list The Tool fixture exposes the persisted policy dimensions under test.
 */
function removal_tool(
    Node $node,
    ToolManagerName $managerName,
    string $package,
    bool $protected = false,
    ToolStatus $toolStatus = ToolStatus::Installed,
    string $managerVersion = '1.0.0',
): array {
    $manager = $node->toolManagers()->create([
        'name' => $managerName,
        'status' => LifecycleStatus::Active,
        'installed_version' => $managerVersion,
    ]);
    $tool = $node->tools()->create([
        'tool_manager_id' => $manager->id,
        'package' => $package,
        'protected' => $protected,
        'status' => $toolStatus,
        'installed_version' => '2.4.1',
    ]);

    return [$manager, $tool];
}

/**
 * @return array{Node, NodeRole, 2?: NodeRoleDependencySet}
 * @mago-expect lint:no-boolean-flag-parameter The fixture optionally creates the dependent graph under test.
 */
function removal_role_fixture(bool $withDependents = false, RoleName $role = RoleName::AppDev): array
{
    $node = removal_node('remove-node-'.strtolower(fake()->bothify('??##')));
    $assignment = $node->roles()->create([
        'role' => $role,
        'status' => LifecycleStatus::Active,
    ]);

    if (! $withDependents) {
        return [$node, $assignment];
    }

    $instance = removal_instance(
        node: $node,
        slug: 'remove-app-'.strtolower(fake()->bothify('??##')),
        certificateMode: CertificateMode::OrbitCa,
        environment: 'development',
    );
    $workspace = removal_workspace(instance: $instance, name: 'feature', status: LifecycleStatus::Active);
    $process = removal_process(owner: $workspace, name: 'worker', status: LifecycleStatus::Active);
    $dependencies = new NodeRoleDependencySet(
        instanceIds: [$instance->id],
        workspaceIds: [$workspace->id],
        processIds: [$process->id],
        summaries: ['1 development instance record', '1 process record', '1 workspace record'],
    );

    return [$node, $assignment, $dependencies];
}

function removal_node(string $name): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.91',
    ]);
}

function removal_instance(
    Node $node,
    string $slug,
    CertificateMode $certificateMode,
    string $environment,
): Instance {
    $app = App::query()->create([
        'name' => ucfirst($slug),
        'slug' => $slug,
        'repository_url' => "git@example.test:{$slug}.git",
    ]);

    return Instance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'main',
        'environment' => $environment,
        'checkout_path' => "/srv/{$slug}",
        'document_root' => 'public',
        'php_version' => '8.5',
        'hostname' => "{$slug}.example.test",
        'certificate_mode' => $certificateMode,
        'status' => LifecycleStatus::Active,
    ]);
}

function removal_workspace(Instance $instance, string $name, LifecycleStatus $status): Workspace
{
    return Workspace::query()->create([
        'instance_id' => $instance->id,
        'name' => $name,
        'branch' => $name,
        'checkout_path' => "{$instance->checkout_path}/{$name}",
        'hostname' => "{$name}.{$instance->hostname}",
        'status' => $status,
    ]);
}

function removal_process(Instance|Workspace $owner, string $name, LifecycleStatus $status): Process
{
    return Process::query()->create([
        'owner_type' => $owner::class,
        'owner_id' => $owner->id,
        'name' => $name,
        'runtime' => 'systemd',
        'working_directory' => $owner->checkout_path,
        'runtime_config' => ['command' => ['/usr/bin/true']],
        'restart_policy' => 'never',
        'desired_state' => 'stopped',
        'status' => $status,
    ]);
}

function removal_dependency_rows_exist(NodeRoleDependencySet $dependencies): bool
{
    return (
        \App\Models\Instance::query()->whereIn('id', $dependencies->instanceIds)->exists()
        && \App\Models\Workspace::query()->whereIn('id', $dependencies->workspaceIds)->exists()
        && \App\Models\Process::query()->whereIn('id', $dependencies->processIds)->exists()
    );
}

final class RemovalInspectorFake implements NodeRoleDependencyInspector
{
    public function __construct(
        public NodeRoleDependencySet $dependencies,
    ) {}

    public function inspect(Node $node, RoleName $role): NodeRoleDependencySet
    {
        return $this->dependencies;
    }
}

/** @mago-expect lint:single-class-per-file Small test fakes stay next to their single consumer. */
final class RemovalToolIntentGuardFake implements NodeRoleToolIntentGuard
{
    public int $assertCalls = 0;

    public ?Closure $onAssert = null;

    /** @return list<string> */
    public function preview(Node $node, RoleName $role): array
    {
        return [];
    }

    /** @return list<string> */
    public function retirementPreview(Node $node, RoleName $role): array
    {
        return [];
    }

    public function assertRemovalSafe(Node $node, RoleName $role): void
    {
        $this->assertCalls++;

        if ($this->onAssert instanceof Closure) {
            ($this->onAssert)($this->assertCalls);
        }
    }

    public function retireUnsupportedManagers(Node $node): void {}
}

/** @mago-expect lint:single-class-per-file Small test fakes stay next to their single consumer. */
final class RemovalCleanerFake implements NodeRoleDependentCleaner
{
    public int $calls = 0;

    /** @var list<LifecycleStatus> */
    public array $observedStatuses = [];

    public ?Throwable $failure = null;

    public ?Closure $afterClean = null;

    /** @var list<string> */
    public array $events = [];

    public function clean(NodeRoleDependencySet $dependencies): void
    {
        $this->calls++;
        $this->events[] = 'clean:'.DB::transactionLevel();
        if ($dependencies->processIds !== []) {
            $this->observedStatuses = [
                \App\Models\Process::query()->findOrFail($dependencies->processIds[0])->status,
                \App\Models\Workspace::query()->findOrFail($dependencies->workspaceIds[0])->status,
                \App\Models\Instance::query()->findOrFail($dependencies->instanceIds[0])->status,
            ];
        }

        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }

        if ($this->afterClean instanceof Closure) {
            ($this->afterClean)();
        }
    }
}

/** @mago-expect lint:single-class-per-file Small test fakes stay next to their single consumer. */
final class RemovalBaselineFake implements RoleBaselineConverger
{
    public int $calls = 0;

    public ?Throwable $failure = null;

    /** @var list<string> */
    public array $events = [];

    public function converge(Node $node, NodeRole $assignment): void {}

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void
    {
        $this->calls++;
        $this->events[] = 'baseline:'.(int) $purgeData.':'.DB::transactionLevel();

        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }
    }

    public function removeUnreachable(Node $node, NodeRole $assignment): void
    {
        $this->calls++;
        $this->events[] = 'baseline-unreachable:'.DB::transactionLevel();

        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }
    }
}
