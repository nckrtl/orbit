<?php

declare(strict_types=1);

use App\Actions\Tools\InstallToolAction;
use App\Data\Tools\InstallToolData;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerRegistry;
use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolOperationException;
use App\Domain\Tools\ToolOutcome;
use App\Domain\Tools\ToolStatus;
use App\Domain\Tools\VersionConstraint;
use App\Models\Node;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Tests\Support\FakeToolManager;
use Tests\Support\ImmediateToolOperationLock;

/** @mago-expect lint:halstead The install matrix keeps every required state transition and failure contract visible. */
describe(InstallToolAction::class, function (): void {
    it('rejects an unsupported manager before node I/O', function (): void {
        [$action, $manager, $lock] = tool_install_action();

        $exception = tool_operation_exception(fn () => $action->execute(new InstallToolData(
            nodeId: 999,
            manager: ToolManagerName::Vp->value,
            package: '@openai/codex',
            versionConstraint: null,
        )));

        expect($exception->errorCode)
            ->toBe('tool.manager_unsupported')
            ->and($exception->status)
            ->toBe(422)
            ->and($exception->outcome)
            ->toBe(ToolOutcome::ManagerFailed)
            ->and($manager->calls)
            ->toBeEmpty()
            ->and($lock->runs)
            ->toBe(0)
            ->and(Tool::query()->count())
            ->toBe(0);
    });

    it('rejects an invalid package before node I/O', function (): void {
        [$action, $manager, $lock] = tool_install_action();
        $manager->validPackage = false;

        $exception = tool_operation_exception(fn () => $action->execute(new InstallToolData(
            nodeId: 999,
            manager: ToolManagerName::Apt->value,
            package: '--option',
            versionConstraint: null,
        )));

        expect($exception->errorCode)
            ->toBe('tool.package_invalid')
            ->and($exception->status)
            ->toBe(422)
            ->and($manager->calls)
            ->toBe(['validatePackage'])
            ->and($lock->runs)
            ->toBe(0)
            ->and(Tool::query()->count())
            ->toBe(0);
    });

    it('rejects an invalid constraint before node I/O', function (): void {
        [$action, $manager, $lock] = tool_install_action();

        $exception = tool_operation_exception(fn () => $action->execute(new InstallToolData(
            nodeId: 999,
            manager: ToolManagerName::Apt->value,
            package: 'jq',
            versionConstraint: 'not a constraint',
        )));

        expect($exception->errorCode)
            ->toBe('tool.constraint_invalid')
            ->and($exception->status)
            ->toBe(422)
            ->and($exception->outcome)
            ->toBe(ToolOutcome::ConstraintInvalid)
            ->and($manager->calls)
            ->toBe(['validatePackage'])
            ->and($lock->runs)
            ->toBe(0)
            ->and(Tool::query()->count())
            ->toBe(0);
    });

    it('rejects inactive nodes and managers before package probes', function (
        LifecycleStatus $nodeStatus,
        LifecycleStatus $managerStatus,
        string $errorCode,
    ): void {
        $node = tool_action_node(status: $nodeStatus);
        tool_action_manager_record($node, status: $managerStatus);
        [$action, $manager, $lock] = tool_install_action();

        $exception = tool_operation_exception(fn () => $action->execute(tool_install_data($node)));

        expect($exception->errorCode)
            ->toBe($errorCode)
            ->and($exception->status)
            ->toBe(409)
            ->and($manager->calls)
            ->toBe(['validatePackage'])
            ->and($lock->runs)
            ->toBe(0)
            ->and(Tool::query()->count())
            ->toBe(0);
    })->with([
        'inactive node' => [LifecycleStatus::Failed, LifecycleStatus::Active, 'tool.node_inactive'],
        'inactive manager' => [LifecycleStatus::Active, LifecycleStatus::Failed, 'tool.manager_unavailable'],
    ]);

    it('requires an active app role for VP before manager I/O', function (): void {
        $node = tool_action_node();
        tool_action_manager_record($node, ToolManagerName::Vp);
        [$action, $manager, $lock] = tool_install_action(ToolManagerName::Vp);

        $exception = tool_operation_exception(fn () => $action->execute(tool_install_data(
            node: $node,
            manager: ToolManagerName::Vp,
            package: '@openai/codex',
        )));

        expect($exception->errorCode)
            ->toBe('tool.app_role_required')
            ->and($exception->status)
            ->toBe(409)
            ->and($manager->calls)
            ->toBe(['validatePackage'])
            ->and($lock->runs)
            ->toBe(0)
            ->and(Tool::query()->count())
            ->toBe(0);
    });

    it('allows provisioning app roles for app-scoped managers', function (
        ToolManagerName $managerName,
        RoleName $role,
        string $package,
    ): void {
        $node = tool_action_node(role: $role, roleStatus: LifecycleStatus::Provisioning);
        tool_action_manager_record($node, $managerName);
        [$action, $manager, $lock] = tool_install_action($managerName);
        $manager->installedVersions = [null, '2.4.1'];

        $result = $action->execute(tool_install_data(
            node: $node,
            manager: $managerName,
            package: $package,
        ));
        $tool = Tool::query()->sole();

        expect($result->outcome)
            ->toBe(ToolOutcome::Applied)
            ->and($tool->status)
            ->toBe(ToolStatus::Installed)
            ->and($tool->installed_version)
            ->toBe('2.4.1')
            ->and($manager->calls)
            ->toBe(['validatePackage', 'installedVersion', 'install', 'installedVersion'])
            ->and($lock->runs)
            ->toBe(1);
    })->with([
        'VP with provisioning app-dev' => [ToolManagerName::Vp, RoleName::AppDev, '@openai/codex'],
        'Composer with provisioning app-prod' => [ToolManagerName::Composer, RoleName::AppProd, 'laravel/installer'],
    ]);

    it('reports the missing app role before unavailable app-scoped manager state', function (): void {
        $node = tool_action_node();
        tool_action_manager_record($node, ToolManagerName::Vp, LifecycleStatus::Failed);
        [$action, $manager, $lock] = tool_install_action(ToolManagerName::Vp);

        $exception = tool_operation_exception(fn () => $action->execute(tool_install_data(
            node: $node,
            manager: ToolManagerName::Vp,
            package: '@openai/codex',
        )));

        expect($exception->errorCode)
            ->toBe('tool.app_role_required')
            ->and($manager->calls)
            ->toBe(['validatePackage'])
            ->and($lock->runs)
            ->toBe(0);
    });

    it('rejects a manager that does not support the node', function (): void {
        $node = tool_action_node();
        tool_action_manager_record($node);
        [$action, $manager, $lock] = tool_install_action();
        $manager->supports = false;

        $exception = tool_operation_exception(fn () => $action->execute(tool_install_data($node)));

        expect($exception->errorCode)
            ->toBe('tool.manager_unavailable')
            ->and($manager->calls)
            ->toBe(['validatePackage'])
            ->and($lock->runs)
            ->toBe(0)
            ->and(Tool::query()->count())
            ->toBe(0);
    });

    it('installs a new unrestricted package and stores its raw version', function (): void {
        $node = tool_action_node();
        tool_action_manager_record($node);
        [$action, $manager, $lock] = tool_install_action();
        $manager->installedVersions = [null, '2.4.1-1ubuntu1'];

        $result = $action->execute(tool_install_data($node));
        $tool = Tool::query()->sole();

        expect($result->created)
            ->toBeTrue()
            ->and($result->outcome)
            ->toBe(ToolOutcome::Applied)
            ->and($result->tool->is($tool))
            ->toBeTrue()
            ->and($tool->status)
            ->toBe(ToolStatus::Installed)
            ->and($tool->installed_version)
            ->toBe('2.4.1-1ubuntu1')
            ->and($tool->failed_operation)
            ->toBeNull()
            ->and($tool->error_code)
            ->toBeNull()
            ->and($manager->calls)
            ->toBe(['validatePackage', 'installedVersion', 'install', 'installedVersion'])
            ->and($lock->runs)
            ->toBe(1);
    });

    it('returns unchanged for the same installed intent', function (?string $constraint): void {
        $node = tool_action_node();
        $record = tool_action_manager_record($node);
        tool_action_tool($node, $record, versionConstraint: $constraint);
        [$action, $manager, $lock] = tool_install_action();
        $manager->installedVersions = ['2.4.1'];

        $result = $action->execute(tool_install_data($node, versionConstraint: $constraint));
        $tool = Tool::query()->sole();

        expect($result->created)
            ->toBeFalse()
            ->and($result->outcome)
            ->toBe(ToolOutcome::Unchanged)
            ->and($tool->status)
            ->toBe(ToolStatus::Installed)
            ->and($tool->installed_version)
            ->toBe('2.4.1')
            ->and($manager->calls)
            ->toBe(['validatePackage', 'installedVersion'])
            ->and($lock->runs)
            ->toBe(1)
            ->and(Tool::query()->count())
            ->toBe(1);
    })->with([
        'null constraint' => null,
        'string constraint' => '^2.4',
    ]);

    it('repairs a failed install when the remote operation already completed', function (): void {
        $node = tool_action_node();
        $record = tool_action_manager_record($node);
        $original = tool_action_tool(
            node: $node,
            record: $record,
            status: ToolStatus::Failed,
            versionConstraint: '^2.4',
            failedOperation: ToolOperation::Install,
            errorCode: 'tool.version_probe_failed',
        );
        [$action, $manager] = tool_install_action();
        $manager->installedVersions = ['2.4.1'];

        $result = $action->execute(tool_install_data($node, versionConstraint: '^2.4'));
        $tool = Tool::query()->sole();

        expect($result->tool->id)
            ->toBe($original->id)
            ->and($result->outcome)
            ->toBe(ToolOutcome::Unchanged)
            ->and($tool->status)
            ->toBe(ToolStatus::Installed)
            ->and($tool->failed_operation)
            ->toBeNull()
            ->and($tool->error_code)
            ->toBeNull()
            ->and(Tool::query()->count())
            ->toBe(1)
            ->and($manager->calls)
            ->toBe(['validatePackage', 'installedVersion']);
    });

    it('rejects an installed unmanaged package without creating intent', function (): void {
        $node = tool_action_node();
        tool_action_manager_record($node);
        [$action, $manager, $lock] = tool_install_action();
        $manager->installedVersions = ['2.4.1'];

        $exception = tool_operation_exception(fn () => $action->execute(tool_install_data($node)));

        expect($exception->errorCode)
            ->toBe('tool.already_installed_unmanaged')
            ->and($exception->status)
            ->toBe(409)
            ->and($manager->calls)
            ->toBe(['validatePackage', 'installedVersion'])
            ->and($lock->runs)
            ->toBe(1)
            ->and(Tool::query()->count())
            ->toBe(0);
    });

    it('rejects a conflicting stored constraint without changing intent', function (): void {
        $node = tool_action_node();
        $record = tool_action_manager_record($node);
        $tool = tool_action_tool($node, $record, versionConstraint: '^2.4');
        [$action, $manager, $lock] = tool_install_action();

        $exception = tool_operation_exception(fn () => $action->execute(tool_install_data(
            node: $node,
            versionConstraint: '^3.0',
        )));

        expect($exception->errorCode)
            ->toBe('tool.constraint_conflict')
            ->and($exception->status)
            ->toBe(409)
            ->and($manager->calls)
            ->toBe(['validatePackage'])
            ->and($lock->runs)
            ->toBe(1)
            ->and($tool->refresh()->version_constraint)
            ->toBe('^2.4')
            ->and(Tool::query()->count())
            ->toBe(1);
    });

    it('retains exact bounded constraint failures in one intent row', function (
        string $rawCandidate,
        string $errorCode,
        ToolOutcome $outcome,
        int $status,
    ): void {
        $node = tool_action_node();
        tool_action_manager_record($node);
        [$action, $manager] = tool_install_action();
        $manager->installedVersions = [null];
        $manager->candidateVersions = [$rawCandidate === 'missing' ? null : $rawCandidate];

        $exception = tool_operation_exception(fn () => $action->execute(tool_install_data(
            node: $node,
            versionConstraint: '^2.4',
        )));
        $tool = Tool::query()->sole();

        expect($exception->errorCode)
            ->toBe($errorCode)
            ->and($exception->outcome)
            ->toBe($outcome)
            ->and($exception->status)
            ->toBe($status)
            ->and($tool->status)
            ->toBe(ToolStatus::Failed)
            ->and($tool->failed_operation)
            ->toBe(ToolOperation::Install)
            ->and($tool->error_code)
            ->toBe($errorCode)
            ->and($tool->installed_version)
            ->toBeNull()
            ->and($manager->calls)
            ->toBe(['validatePackage', 'installedVersion', 'candidateVersion'])
            ->and(Tool::query()->count())
            ->toBe(1);
    })->with([
        'missing candidate' => [
            'missing',
            'tool.candidate_version_unavailable',
            ToolOutcome::CandidateVersionUnavailable,
            422,
        ],
        'unparseable candidate' => [
            'release-2.4',
            'tool.candidate_version_unparseable',
            ToolOutcome::CandidateVersionUnparseable,
            422,
        ],
        'blocked candidate' => ['3.0.0', 'tool.version_constraint_blocked', ToolOutcome::BlockedByConstraint, 422],
    ]);

    it('retains a new failed intent when the initial installed-version probe fails', function (): void {
        $node = tool_action_node();
        tool_action_manager_record($node);
        [$action, $manager, $lock] = tool_install_action();
        $probeFailure = new ToolManagerException('installed-version', 'Probe failed.');
        $manager->installedVersions = [$probeFailure];

        $exception = tool_operation_exception(fn () => $action->execute(tool_install_data($node)));
        $tool = Tool::query()->sole();

        expect($exception->errorCode)
            ->toBe('tool.version_probe_failed')
            ->and($exception->status)
            ->toBe(502)
            ->and($exception->getPrevious())
            ->toBe($probeFailure)
            ->and($tool->status)
            ->toBe(ToolStatus::Failed)
            ->and($tool->failed_operation)
            ->toBe(ToolOperation::Install)
            ->and($tool->error_code)
            ->toBe('tool.version_probe_failed')
            ->and($manager->calls)
            ->toBe(['validatePackage', 'installedVersion'])
            ->and($lock->runs)
            ->toBe(1)
            ->and(Tool::query()->count())
            ->toBe(1);
    });

    it('retains candidate probe failures without command output', function (): void {
        $node = tool_action_node();
        tool_action_manager_record($node);
        [$action, $manager] = tool_install_action();
        $manager->installedVersions = [null];
        $manager->candidateVersions = [new ToolManagerException('install', 'Candidate lookup failed.')];

        $exception = tool_operation_exception(fn () => $action->execute(tool_install_data(
            node: $node,
            versionConstraint: '^2.4',
        )));
        $tool = Tool::query()->sole();

        expect($exception->errorCode)
            ->toBe('tool.candidate_version_probe_failed')
            ->and($exception->outcome)
            ->toBe(ToolOutcome::CandidateVersionUnavailable)
            ->and($exception->status)
            ->toBe(502)
            ->and($tool->status)
            ->toBe(ToolStatus::Failed)
            ->and($tool->failed_operation)
            ->toBe(ToolOperation::Install)
            ->and($tool->error_code)
            ->toBe('tool.candidate_version_probe_failed');
    });

    it('retains manager install failures for deterministic retry', function (): void {
        $node = tool_action_node();
        tool_action_manager_record($node);
        [$action, $manager] = tool_install_action();
        $manager->installedVersions = [null];
        $manager->failures['install'] = [new ToolManagerException('candidate-version', 'Install failed.')];

        $exception = tool_operation_exception(fn () => $action->execute(tool_install_data($node)));
        $tool = Tool::query()->sole();

        expect($exception->errorCode)
            ->toBe('tool.install_failed')
            ->and($exception->status)
            ->toBe(502)
            ->and($tool->status)
            ->toBe(ToolStatus::Failed)
            ->and($tool->failed_operation)
            ->toBe(ToolOperation::Install)
            ->and($tool->error_code)
            ->toBe('tool.install_failed')
            ->and($manager->calls)
            ->toBe(['validatePackage', 'installedVersion', 'install']);
    });

    it('retains installed-version probe failures after mutation', function (?ToolManagerException $probe): void {
        $node = tool_action_node();
        tool_action_manager_record($node);
        [$action, $manager] = tool_install_action();
        $manager->installedVersions = [null, $probe];

        $exception = tool_operation_exception(fn () => $action->execute(tool_install_data($node)));
        $tool = Tool::query()->sole();

        expect($exception->errorCode)
            ->toBe('tool.version_probe_failed')
            ->and($exception->status)
            ->toBe(502)
            ->and($tool->status)
            ->toBe(ToolStatus::Failed)
            ->and($tool->failed_operation)
            ->toBe(ToolOperation::Install)
            ->and($tool->error_code)
            ->toBe('tool.version_probe_failed')
            ->and($manager->calls)
            ->toBe(['validatePackage', 'installedVersion', 'install', 'installedVersion']);
    })->with([
        'missing installed version' => null,
        'failed installed probe' => new ToolManagerException('install', 'Installed lookup failed.'),
    ]);

    it('retains the raw version when constrained post-install verification fails', function (
        string $rawVersion,
        string $errorCode,
    ): void {
        $node = tool_action_node();
        tool_action_manager_record($node);
        [$action, $manager] = tool_install_action();
        $manager->installedVersions = [null, $rawVersion];
        $manager->candidateVersions = ['2.4.1'];

        $exception = tool_operation_exception(fn () => $action->execute(tool_install_data(
            node: $node,
            versionConstraint: '^2.4',
        )));
        $tool = Tool::query()->sole();

        expect($exception->errorCode)
            ->toBe($errorCode)
            ->and($exception->status)
            ->toBe(409)
            ->and($exception->outcome)
            ->toBe(ToolOutcome::ManagerFailed)
            ->and($tool->status)
            ->toBe(ToolStatus::Failed)
            ->and($tool->failed_operation)
            ->toBe(ToolOperation::Install)
            ->and($tool->installed_version)
            ->toBe($rawVersion)
            ->and($tool->error_code)
            ->toBe($errorCode);
    })->with([
        'unparseable' => ['release-2.4', 'tool.installed_version_unparseable'],
        'outside constraint' => ['3.0.0', 'tool.installed_version_constraint_violated'],
    ]);

    it('fails closed when an existing constrained live version violates intent', function (
        string $rawVersion,
        string $errorCode,
    ): void {
        $node = tool_action_node();
        $record = tool_action_manager_record($node);
        tool_action_tool($node, $record, versionConstraint: '^2.4');
        [$action, $manager] = tool_install_action();
        $manager->installedVersions = [$rawVersion];

        $exception = tool_operation_exception(fn () => $action->execute(tool_install_data(
            node: $node,
            versionConstraint: '^2.4',
        )));
        $tool = Tool::query()->sole();

        expect($exception->errorCode)
            ->toBe($errorCode)
            ->and($exception->status)
            ->toBe(409)
            ->and($exception->outcome)
            ->toBe(ToolOutcome::ManagerFailed)
            ->and($tool->status)
            ->toBe(ToolStatus::Failed)
            ->and($tool->failed_operation)
            ->toBe(ToolOperation::Install)
            ->and($tool->installed_version)
            ->toBe($rawVersion)
            ->and($manager->calls)
            ->toBe(['validatePackage', 'installedVersion']);
    })->with([
        'unparseable live version' => ['release-2.4', 'tool.installed_version_unparseable'],
        'outside live version' => ['3.0.0', 'tool.installed_version_constraint_violated'],
    ]);

    it('retains bounded retry state when an existing live version is unsafe', function (): void {
        $node = tool_action_node();
        $record = tool_action_manager_record($node);
        tool_action_tool($node, $record);
        [$action, $manager] = tool_install_action();
        $manager->installedVersions = [str_repeat('x', times: 256)];

        $exception = tool_operation_exception(fn () => $action->execute(tool_install_data($node)));
        $tool = Tool::query()->sole();

        expect($exception->errorCode)
            ->toBe('tool.version_probe_failed')
            ->and($tool->status)
            ->toBe(ToolStatus::Failed)
            ->and($tool->failed_operation)
            ->toBe(ToolOperation::Install)
            ->and($tool->installed_version)
            ->toBe('2.4.0');
    });

    it('does not retain an unsafe raw version after install mutation', function (): void {
        $node = tool_action_node();
        tool_action_manager_record($node);
        [$action, $manager] = tool_install_action();
        $manager->installedVersions = [null, str_repeat('x', times: 256)];

        $exception = tool_operation_exception(fn () => $action->execute(tool_install_data($node)));
        $tool = Tool::query()->sole();

        expect($exception->errorCode)
            ->toBe('tool.version_probe_failed')
            ->and($tool->status)
            ->toBe(ToolStatus::Failed)
            ->and($tool->failed_operation)
            ->toBe(ToolOperation::Install)
            ->and($tool->installed_version)
            ->toBeNull();
    });

    it('rejects an invalid persisted transition', function (): void {
        $node = tool_action_node();
        $record = tool_action_manager_record($node);
        tool_action_tool($node, $record, status: ToolStatus::Updating);
        [$action, $manager] = tool_install_action();

        $exception = tool_operation_exception(fn () => $action->execute(tool_install_data($node)));

        expect($exception->errorCode)
            ->toBe('tool.state_invalid')
            ->and($exception->status)
            ->toBe(409)
            ->and($manager->calls)
            ->toBe(['validatePackage'])
            ->and(Tool::query()->sole()->status)
            ->toBe(ToolStatus::Updating);
    });

    it('passes the exact intent through the operation lock', function (): void {
        $node = tool_action_node();
        tool_action_manager_record($node);
        [$action, $manager, $lock] = tool_install_action();
        $manager->installedVersions = [null, '2.4.1'];
        $manager->candidateVersions = ['2.4.1'];

        $action->execute(tool_install_data($node, versionConstraint: '^2.4'));

        expect($lock->arguments)->toBe([[
            'nodeId' => $node->id,
            'manager' => ToolManagerName::Apt,
            'package' => 'jq',
            'operation' => ToolOperation::Install,
            'versionConstraint' => '^2.4',
        ]]);
    });
});

/** @return array{InstallToolAction, FakeToolManager, ImmediateToolOperationLock} */
function tool_install_action(ToolManagerName $name = ToolManagerName::Apt): array
{
    $manager = new FakeToolManager($name);
    $lock = new ImmediateToolOperationLock;

    return [
        new InstallToolAction(
            managers: new ToolManagerRegistry([$manager]),
            constraints: new VersionConstraint,
            lock: $lock,
        ),
        $manager,
        $lock,
    ];
}

function tool_action_node(
    LifecycleStatus $status = LifecycleStatus::Active,
    ?RoleName $role = null,
    LifecycleStatus $roleStatus = LifecycleStatus::Active,
): Node {
    $node = Node::query()->create([
        'name' => fake()->unique()->slug(2),
        'status' => $status,
        'platform' => 'linux',
        'public_ssh_host' => fake()->unique()->ipv4(),
    ]);

    if ($role !== null) {
        $node->roles()->create([
            'role' => $role,
            'status' => $roleStatus,
        ]);
    }

    return $node;
}

function tool_action_manager_record(
    Node $node,
    ToolManagerName $name = ToolManagerName::Apt,
    LifecycleStatus $status = LifecycleStatus::Active,
): ToolManagerRecord {
    return $node->toolManagers()->create([
        'name' => $name,
        'status' => $status,
    ]);
}

/** @mago-expect lint:excessive-parameter-list The fixture exposes every persisted retry field used by the matrix. */
function tool_action_tool(
    Node $node,
    ToolManagerRecord $record,
    ToolStatus $status = ToolStatus::Installed,
    ?string $versionConstraint = null,
    ?ToolOperation $failedOperation = null,
    ?string $errorCode = null,
): Tool {
    return $node->tools()->create([
        'tool_manager_id' => $record->id,
        'package' => 'jq',
        'version_constraint' => $versionConstraint,
        'status' => $status,
        'installed_version' => '2.4.0',
        'failed_operation' => $failedOperation,
        'error_code' => $errorCode,
    ]);
}

function tool_install_data(
    Node $node,
    ToolManagerName $manager = ToolManagerName::Apt,
    string $package = 'jq',
    ?string $versionConstraint = null,
): InstallToolData {
    return new InstallToolData(
        nodeId: $node->id,
        manager: $manager->value,
        package: $package,
        versionConstraint: $versionConstraint,
    );
}

function tool_operation_exception(Closure $callback): ToolOperationException
{
    try {
        $callback();
    } catch (ToolOperationException $exception) {
        expect($exception->step)->toBe(ToolOperation::Install->value);

        return $exception;
    }

    throw new RuntimeException('Expected a ToolOperationException.');
}
