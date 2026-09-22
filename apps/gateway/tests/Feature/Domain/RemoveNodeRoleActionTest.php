<?php

declare(strict_types=1);

use App\Actions\Nodes\RemoveNodeRoleAction;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Firewall\FirewallOperationException;
use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\NodeSideResidue;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\RoleRegistry;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerScopeLock;
use App\Domain\Tools\ToolStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Process;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeNodeRoleFirewallManager;

describe(RemoveNodeRoleAction::class, function (): void {
    it('rejects app role removal before mutation when a manager scope is busy', function (): void {
        [$node, $assignment] = removal_role_fixture();
        $baseline = new RemovalBaselineFake;
        $scope = Cache::lock("orbit:tool-manager:{$node->id}:vp", 3_600);
        expect($scope->get())->toBeTrue();

        try {
            expect(fn () => removal_action(
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
        $baseline = new RemovalBaselineFake;
        $scope = Cache::lock("orbit:tool-manager:{$node->id}:vp", 3_600);
        expect($scope->get())->toBeTrue();

        try {
            expect(fn () => removal_action(
                $baseline,
            )->execute($node, RoleName::AppDev, force: true))
                ->toThrow(NodeRoleOperationException::class);
        } finally {
            $scope->release();
        }

        expect($assignment->refresh()->status)
            ->toBe(LifecycleStatus::Active)
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
        $baseline = new RemovalBaselineFake;
        $action = removal_action($baseline);

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
            ->and($baseline->calls)
            ->toBe(0);
    });

    it('omits retained managers from the last app role preview', function (): void {
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
            new RemovalBaselineFake,
        );

        expect(fn () => $action->execute($node, RoleName::AppDev, force: false))
            ->toThrow(function (NodeRoleValidationException $exception): void {
                expect($exception->details['dependents'])->toBeEmpty();
            });

        $action->execute($node, RoleName::AppDev, force: true);

        expect($node->toolManagers()->count())->toBe(2);
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
            new RemovalBaselineFake,
        );

        expect(fn () => $action->execute($node, RoleName::AppDev, force: false))
            ->toThrow(function (NodeRoleValidationException $exception): void {
                expect($exception->details['dependents'])->toBeEmpty();
            });
    });

    it('removes either final app role while retaining Tool intent', function (RoleName $role): void {
        [$node, $assignment] = removal_role_fixture(role: $role);
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
        $baseline = new RemovalBaselineFake;
        $action = removal_action(
            $baseline,
        );

        $action->execute($node, $role, force: true);

        expect(NodeRole::query()->whereKey($assignment->id)->exists())
            ->toBeFalse()
            ->and($node->tools()->count())
            ->toBe(12)
            ->and($node->toolManagers()->count())
            ->toBe(2)
            ->and($baseline->calls)
            ->toBe(1);
    })->with([
        'app-dev' => RoleName::AppDev,
        'app-prod' => RoleName::AppProd,
    ]);

    it('allows removal of the last active app role when app-scoped Tool intent is protected', function (): void {
        [$node, $assignment] = removal_role_fixture();
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
        $action = removal_action(
            new RemovalBaselineFake,
        );

        $action->execute($node, RoleName::AppDev, force: true);

        expect(NodeRole::query()->whereKey($assignment->id)->exists())
            ->toBeFalse()
            ->and($tool->refresh()->status)
            ->toBe(ToolStatus::Installed);
    });

    it('allows removal with APT and failed VP Tool intent', function (): void {
        [$aptNode, $aptAssignment] = removal_role_fixture();
        removal_tool(node: $aptNode, managerName: ToolManagerName::Apt, package: 'jq', toolStatus: ToolStatus::Failed);

        removal_action(
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

        removal_action(
            new RemovalBaselineFake,
        )->execute($vpNode, RoleName::AppDev, force: true);

        expect(NodeRole::query()->whereKey($vpAssignment->id)->exists())->toBeFalse();
    });

    it('allows app role removal while another active app role remains', function (): void {
        [$node, $assignment] = removal_role_fixture();
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
        $action = removal_action(
            new RemovalBaselineFake,
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
            new RemovalBaselineFake,
        )->execute($node, RoleName::AppDev, force: true);

        expect(NodeRole::query()->whereKey($assignment->id)->exists())
            ->toBeFalse()
            ->and($manager->refresh()->status)
            ->toBe(LifecycleStatus::Active)
            ->and($tool->refresh()->status)
            ->toBe(ToolStatus::Installed);
    });

    it('claims only the role and runs its baseline outside the claim transaction', function (): void {
        [$node, $assignment, $process] = removal_role_fixture(withProcess: true);
        $processState = $process->refresh()->getAttributes();
        $baseline = new RemovalBaselineFake;
        $action = removal_action($baseline);
        $ambientTransactionLevel = DB::transactionLevel();

        $removed = $action->execute($node, RoleName::AppDev, force: true, purgeData: true);

        expect($baseline->events)
            ->toBe(["baseline:1:{$ambientTransactionLevel}"])
            ->and($baseline->observedStatuses)
            ->toBe([LifecycleStatus::Removing])
            ->and($removed->degradation)
            ->toBeNull()
            ->and($removed->retained)
            ->toBe([])
            ->and(NodeRole::query()->whereKey($assignment->id)->exists())
            ->toBeFalse()
            ->and($process->refresh()->getAttributes())
            ->toBe($processState);
    });

    it('preserves AppInstances and their processes when removing an unrelated role', function (): void {
        [$node, $assignment, $nodeProcess] = removal_role_fixture(withProcess: true, role: RoleName::Database);
        $instance = removal_app_instance($node);
        $instanceProcess = removal_process(owner: $instance, name: 'queue', status: LifecycleStatus::Failed);
        $instanceState = $instance->refresh()->getAttributes();
        $nodeProcessState = $nodeProcess->refresh()->getAttributes();
        $instanceProcessState = $instanceProcess->refresh()->getAttributes();

        removal_action(new RemovalBaselineFake)->execute($node, RoleName::Database, force: true);

        expect(NodeRole::query()->whereKey($assignment->id)->exists())
            ->toBeFalse()
            ->and($instance->refresh()->getAttributes())
            ->toBe($instanceState)
            ->and($nodeProcess->refresh()->getAttributes())
            ->toBe($nodeProcessState)
            ->and($instanceProcess->refresh()->getAttributes())
            ->toBe($instanceProcessState);
    });

    it('rechecks AppInstance ownership under the role claim before teardown', function (): void {
        [$node, $assignment] = removal_role_fixture();
        $baseline = new RemovalBaselineFake;
        $scope = Mockery::mock(ToolManagerScopeLock::class);
        $scope->shouldReceive('run')
            ->once()
            ->with($node->id, ToolManagerName::Vp, Mockery::type(Closure::class))
            ->andReturnUsing(static function (int $nodeId, ToolManagerName $manager, Closure $callback) use ($node): mixed {
                removal_app_instance($node);

                return $callback();
            });
        $scope->shouldReceive('run')
            ->once()
            ->with($node->id, ToolManagerName::Composer, Mockery::type(Closure::class))
            ->andReturnUsing(static fn (int $nodeId, ToolManagerName $manager, Closure $callback): mixed => $callback());
        app()->instance(ToolManagerScopeLock::class, $scope);

        expect(fn () => removal_action($baseline)->execute($node, RoleName::AppDev, force: true))
            ->toThrow(function (NodeRoleValidationException $exception): void {
                expect($exception->details['reason'])->toBe('app_instances_attached');
            });

        expect($assignment->refresh()->status)
            ->toBe(LifecycleStatus::Active)
            ->and($baseline->calls)
            ->toBe(0)
            ->and($node->appInstances()->count())
            ->toBe(1);
    });

    it('retains active managers and every Tool after final app role removal', function (): void {
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
            ->toBe(LifecycleStatus::Active)
            ->and($vpManager->failed_step)
            ->toBeNull()
            ->and($vpManager->error_code)
            ->toBeNull()
            ->and($vpManager->installed_version)
            ->toBe('1.2.3')
            ->and($composerManager->refresh()->status)
            ->toBe(LifecycleStatus::Active)
            ->and($composerManager->installed_version)
            ->toBe('2.8.1')
            ->and($aptTool->refresh()->installed_version)
            ->toBe('2.4.1')
            ->and($vpTool->refresh()->installed_version)
            ->toBe('2.4.1')
            ->and($composerTool->refresh()->installed_version)
            ->toBe('2.4.1');
    });

    it('retains managers when another app role is failed or removing', function (LifecycleStatus $status): void {
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
            new RemovalBaselineFake,
        )->execute($node, RoleName::AppDev, force: true);

        expect($manager->refresh()->status)
            ->toBe(LifecycleStatus::Active)
            ->and($manager->failed_step)
            ->toBeNull();
    })->with([
        'failed assignment' => LifecycleStatus::Failed,
        'removing assignment' => LifecycleStatus::Removing,
    ]);

    it('keeps only the role retryable when its baseline fails', function (): void {
        [$node, $assignment, $process] = removal_role_fixture(withProcess: true);
        $processState = $process->refresh()->getAttributes();
        [$manager, $tool] = removal_tool(
            node: $node,
            managerName: ToolManagerName::Vp,
            package: '@openai/codex',
            protected: true,
        );
        $managerState = $manager->refresh()->getAttributes();
        $toolState = $tool->refresh()->getAttributes();
        $baseline = new RemovalBaselineFake;
        $baseline->failure = new RuntimeException('baseline failed');
        $action = removal_action($baseline);

        expect(fn () => $action->execute($node, RoleName::AppDev, force: true))
            ->toThrow(NodeRoleOperationException::class);

        expect($assignment->refresh()->status)
            ->toBe(LifecycleStatus::Failed)
            ->and($assignment->failed_step)
            ->toBe('remove:baseline')
            ->and($assignment->error_code)
            ->toBe('node_role.remove_unknown')
            ->and($process->refresh()->getAttributes())
            ->toBe($processState)
            ->and($manager->refresh()->getAttributes())
            ->toBe($managerState)
            ->and($tool->refresh()->getAttributes())
            ->toBe($toolState);

        $baseline->failure = null;
        $removed = $action->execute($node, RoleName::AppDev, force: true);

        expect($removed->degradation)
            ->toBeNull()
            ->and($removed->retained)
            ->toBe([])
            ->and(NodeRole::query()->whereKey($assignment->id)->exists())
            ->toBeFalse()
            ->and($baseline->calls)
            ->toBe(2)
            ->and($process->refresh()->getAttributes())
            ->toBe($processState)
            ->and($manager->refresh()->getAttributes())
            ->toBe($managerState)
            ->and($tool->refresh()->getAttributes())
            ->toBe($toolState);
    });

    it('records a finalization failure on the role and permits removal retry', function (): void {
        [$node, $assignment, $process] = removal_role_fixture(withProcess: true);
        $processState = $process->refresh()->getAttributes();
        $baseline = new RemovalBaselineFake;
        $action = removal_action($baseline);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER role_removal_finalize_failure
            BEFORE DELETE ON node_roles
            BEGIN
                SELECT RAISE(ABORT, 'Injected role deletion failure.');
            END
            SQL);

        try {
            expect(fn () => $action->execute($node, RoleName::AppDev, force: true))
                ->toThrow(function (NodeRoleOperationException $exception): void {
                    expect($exception->step)
                        ->toBe('remove:finalize')
                        ->and($exception->errorCode)
                        ->toBe('node_role.remove_failed')
                        ->and($exception->underlyingErrorCode)
                        ->toBe('node_role.finalize_failed');
                });
        } finally {
            DB::unprepared('DROP TRIGGER role_removal_finalize_failure');
        }

        expect($assignment->refresh()->status)
            ->toBe(LifecycleStatus::Failed)
            ->and($assignment->failed_step)
            ->toBe('remove:finalize')
            ->and($assignment->error_code)
            ->toBe('node_role.finalize_failed')
            ->and($process->refresh()->getAttributes())
            ->toBe($processState);

        $action->execute($node, RoleName::AppDev, force: true);

        expect(NodeRole::query()->whereKey($assignment->id)->exists())
            ->toBeFalse()
            ->and($baseline->calls)
            ->toBe(2)
            ->and($process->refresh()->getAttributes())
            ->toBe($processState);
    });

    it('sheds a role from an unreachable node without attempting anything on it', function (): void {
        [$node, $assignment, $process] = removal_role_fixture(withProcess: true);
        $baseline = new RemovalBaselineFake;
        $probe = new RemovalReachabilityFake(ExporterDegradationReason::Unreachable);
        $firewall = new FakeNodeRoleFirewallManager;
        $action = removal_action($baseline, reachability: $probe, firewall: $firewall);

        $removed = $action->execute($node, RoleName::AppDev, force: true, purgeData: false, offline: true);

        expect($probe->calls)
            ->toBe(1)
            ->and($firewall->restored)
            ->toBe([])
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
            ->and($process->refresh()->status)
            ->toBe(LifecycleStatus::Active);
    });

    it('keeps a reachable node fail-closed even when the offline claim is made', function (): void {
        [$node, $assignment, $process] = removal_role_fixture(withProcess: true);
        $baseline = new RemovalBaselineFake;
        $baseline->failure = new RuntimeConvergenceException(
            step: 'baseline-runtime',
            errorCode: 'baseline.runtime_failed',
            message: 'baseline-runtime failed',
        );
        $probe = new RemovalReachabilityFake(null);
        $action = removal_action(
            $baseline,
            reachability: $probe,
        );

        expect(fn () => $action->execute($node, RoleName::AppDev, force: true, purgeData: false, offline: true))
            ->toThrow(NodeRoleOperationException::class);

        expect($probe->calls)
            ->toBe(1)
            ->and($assignment->refresh()->status)
            ->toBe(LifecycleStatus::Failed)
            ->and($assignment->failed_step)
            ->toBe('remove:baseline-runtime')
            ->and($process->refresh()->status)
            ->toBe(LifecycleStatus::Active);
    });

    it('names the offline flag on a node-side teardown failure', function (): void {
        [$node] = removal_role_fixture();
        $baseline = new RemovalBaselineFake;
        $baseline->failure = new RuntimeConvergenceException(
            step: 'baseline-runtime',
            errorCode: 'baseline.runtime_failed',
            message: 'baseline-runtime failed',
        );
        $action = removal_action($baseline);

        expect(fn () => $action->execute($node, RoleName::AppDev, force: true, purgeData: false))
            ->toThrow(
                NodeRoleOperationException::class,
                "baseline-runtime failed Retry with --offline if node [{$node->name}] is unreachable.",
            );
    });

    it('still fails closed when the Gateway side cannot be converged for an unreachable node', function (): void {
        [$node, $assignment, $process] = removal_role_fixture(withProcess: true);
        $baseline = new RemovalBaselineFake;
        $baseline->failure = new RuntimeException('gateway projection failed');
        $action = removal_action(
            $baseline,
            reachability: new RemovalReachabilityFake(ExporterDegradationReason::Unreachable),
        );

        expect(fn () => $action->execute($node, RoleName::AppDev, force: true, purgeData: false, offline: true))
            ->toThrow(NodeRoleOperationException::class);

        expect($assignment->refresh()->status)
            ->toBe(LifecycleStatus::Failed)
            ->and($assignment->failed_step)
            ->toBe('remove:baseline')
            ->and($process->refresh()->status)
            ->toBe(LifecycleStatus::Active);
    });

    it('never probes reachability without the offline claim', function (): void {
        [$node] = removal_role_fixture();
        $probe = new RemovalReachabilityFake(ExporterDegradationReason::Unreachable);
        $action = removal_action(
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

    it('reopens public SSH after tearing down the last role', function (): void {
        [$node, $assignment] = removal_role_fixture();
        $events = [];
        $baseline = new RemovalBaselineFake;
        $baseline->events = &$events;
        $firewall = new FakeNodeRoleFirewallManager;
        $firewall->events = &$events;
        $ambientTransactionLevel = DB::transactionLevel();

        removal_action(
            $baseline,
            firewall: $firewall,
        )->execute($node, RoleName::AppDev, force: true);

        expect($events)
            ->toBe([
                "baseline:0:{$ambientTransactionLevel}",
                "firewall-recovery:{$ambientTransactionLevel}",
            ])
            ->and($firewall->restored)
            ->toBe([$node->id])
            ->and($firewall->restoredUsers)
            ->toBe(['orbit'])
            ->and(NodeRole::query()->whereKey($assignment->id)->exists())
            ->toBeFalse();
    });

    it('keeps public SSH closed while another role row remains', function (LifecycleStatus $status): void {
        [$node, $assignment] = removal_role_fixture();
        $node->roles()->create([
            'role' => RoleName::AppProd,
            'status' => $status,
            'failed_step' => $status === LifecycleStatus::Failed ? 'converge:baseline' : null,
            'error_code' => $status === LifecycleStatus::Failed ? 'app-prod.baseline_failed' : null,
        ]);
        $firewall = new FakeNodeRoleFirewallManager;

        removal_action(
            new RemovalBaselineFake,
            firewall: $firewall,
        )->execute($node, RoleName::AppDev, force: true);

        expect($firewall->restored)
            ->toBe([])
            ->and(NodeRole::query()->whereKey($assignment->id)->exists())
            ->toBeFalse()
            ->and($node->roles()->where('role', RoleName::AppProd)->exists())
            ->toBeTrue();
    })->with([
        'active' => LifecycleStatus::Active,
        'provisioning' => LifecycleStatus::Provisioning,
        'failed' => LifecycleStatus::Failed,
    ]);

    it('keeps the assignment retryable when public SSH cannot be reopened', function (): void {
        [$node, $assignment] = removal_role_fixture();
        $firewall = new FakeNodeRoleFirewallManager;
        $firewall->restoreFailure = new FirewallOperationException(
            step: 'host-firewall',
            errorCode: 'node.firewall_convergence_failed',
            message: 'UFW is inactive during role-rule convergence.',
        );
        $action = removal_action(
            new RemovalBaselineFake,
            firewall: $firewall,
        );

        expect(fn () => $action->execute($node, RoleName::AppDev, force: true))
            ->toThrow(function (NodeRoleOperationException $exception) use ($node): void {
                expect($exception->step)
                    ->toBe('remove:firewall-recovery')
                    ->and($exception->errorCode)
                    ->toBe('node_role.remove_failed')
                    ->and($exception->underlyingErrorCode)
                    ->toBe('node.firewall_recovery_failed')
                    ->and($exception->getMessage())
                    ->toContain("Retry with --offline if node [{$node->name}] is unreachable.");
            });

        expect($assignment->refresh()->status)
            ->toBe(LifecycleStatus::Failed)
            ->and($assignment->failed_step)
            ->toBe('remove:firewall-recovery')
            ->and($assignment->error_code)
            ->toBe('node.firewall_recovery_failed');

        $firewall->restoreFailure = null;
        $action->execute($node, RoleName::AppDev, force: true);

        expect($firewall->restored)
            ->toBe([$node->id, $node->id])
            ->and(NodeRole::query()->whereKey($assignment->id)->exists())
            ->toBeFalse();
    });
});

function removal_action(
    RoleBaselineConverger $baseline,
    ?NodeReachabilityProbe $reachability = null,
    ?NodeRoleFirewallManager $firewall = null,
): RemoveNodeRoleAction {
    return new RemoveNodeRoleAction(
        $baseline,
        app(RoleRegistry::class),
        app(ToolManagerScopeLock::class),
        $reachability ?? new RemovalReachabilityFake(null),
        new NodeSideResidue,
        $firewall ?? new FakeNodeRoleFirewallManager,
    );
}

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
 * @return array{ToolManagerRecord, Tool}
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
 * @return array{Node, NodeRole, 2?: Process}
 */
function removal_role_fixture(bool $withProcess = false, RoleName $role = RoleName::AppDev): array
{
    $node = removal_node('remove-node-'.strtolower(fake()->bothify('??##')));
    $assignment = $node->roles()->create([
        'role' => $role,
        'status' => LifecycleStatus::Active,
    ]);

    if (! $withProcess) {
        return [$node, $assignment];
    }

    return [$node, $assignment, removal_process(owner: $node, name: 'worker', status: LifecycleStatus::Active)];
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

function removal_app_instance(Node $node): AppInstance
{
    $app = OrbitApp::query()->create([
        'name' => 'Retained app',
        'slug' => 'retained-app',
        'repository_url' => 'git@example.test:retained.git',
        'default_branch' => 'main',
    ]);

    return AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'retained',
        'checkout_path' => '/home/orbit/apps/retained',
        'status' => AppInstanceState::Active,
    ]);
}

function removal_process(Node|AppInstance $owner, string $name, LifecycleStatus $status): Process
{
    return Process::query()->create([
        'owner_type' => $owner::class,
        'owner_id' => $owner->id,
        'name' => $name,
        'runtime' => 'systemd',
        'working_directory' => '/home/orbit',
        'runtime_config' => ['command' => ['/usr/bin/true']],
        'restart_policy' => 'never',
        'desired_state' => 'stopped',
        'status' => $status,
    ]);
}

final class RemovalBaselineFake implements RoleBaselineConverger
{
    /** @var list<LifecycleStatus> */
    public array $observedStatuses = [];

    public int $calls = 0;

    public ?Throwable $failure = null;

    /** @var list<string> */
    public array $events = [];

    public function converge(Node $node, NodeRole $assignment): void {}

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void
    {
        $this->calls++;
        $this->events[] = 'baseline:'.(int) $purgeData.':'.DB::transactionLevel();
        $this->observedStatuses[] = $assignment->refresh()->status;

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
