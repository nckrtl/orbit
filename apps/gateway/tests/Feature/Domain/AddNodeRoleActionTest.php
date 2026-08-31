<?php

declare(strict_types=1);

use App\Actions\Nodes\AddNodeRoleAction;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManagerMaterializer;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerRegistry;
use App\Domain\Tools\ToolManagerScopeLock;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Tools\NativeToolManagerMaterializer;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\ToolManagerRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeToolManager;
use Tests\Support\FakeToolManagerMaterializer;

/** @mago-expect lint:halstead The focused group keeps each role lifecycle transition visible. */
describe(AddNodeRoleAction::class, function (): void {
    beforeEach(function (): void {
        app()->instance(ToolManagerMaterializer::class, new FakeToolManagerMaterializer);
    });

    it('maps outer Composer contention without claiming the role', function (): void {
        $baseline = new AddNodeRoleBaselineFake;
        $materializer = new FakeToolManagerMaterializer;
        app()->instance(RoleBaselineConverger::class, $baseline);
        app()->instance(ToolManagerMaterializer::class, $materializer);
        $node = add_role_node();
        $lock = Cache::lock("orbit:tool-manager:{$node->id}:composer", 3_600);
        expect($lock->get())->toBeTrue();

        try {
            expect(fn () => app(AddNodeRoleAction::class)->execute($node, RoleName::AppDev))
                ->toThrow(function (NodeRoleOperationException $exception) use ($node): void {
                    expect($exception->step)
                        ->toBe('converge:tool-manager-lock')
                        ->and($exception->errorCode)
                        ->toBe('node_role.convergence_failed')
                        ->and($exception->underlyingErrorCode)
                        ->toBe('node_role.tool_manager_locked')
                        ->and($exception->getMessage())
                        ->toBe("Tool manager state is busy on node [{$node->name}].");
                });
        } finally {
            $lock->release();
        }

        expect($node->roles()->exists())
            ->toBeFalse()
            ->and($node->toolManagers()->exists())
            ->toBeFalse()
            ->and($baseline->convergedRoles)
            ->toBeEmpty()
            ->and($materializer->requests)
            ->toBeEmpty();
        app(ToolManagerScopeLock::class)->run($node->id, ToolManagerName::Vp, static fn (): null => null);
        app(ToolManagerScopeLock::class)->run($node->id, ToolManagerName::Composer, static fn (): null => null);
    });

    it('materializes app managers after baseline while the assignment is provisioning', function (): void {
        $baseline = new AddNodeRoleBaselineFake;
        $materializer = new FakeToolManagerMaterializer;
        app()->instance(RoleBaselineConverger::class, $baseline);
        app()->instance(ToolManagerMaterializer::class, $materializer);
        $node = add_role_node();

        $result = app(AddNodeRoleAction::class)->execute($node, RoleName::AppDev);

        expect($baseline->observedStatuses)
            ->toBe([LifecycleStatus::Provisioning])
            ->and($materializer->events)
            ->toBe(['vp,composer:provisioning'])
            ->and($materializer->requests)
            ->toBe([[ToolManagerName::Vp, ToolManagerName::Composer]])
            ->and($result['assignment']->status)
            ->toBe(LifecycleStatus::Active);
    });

    it('fails only the app role when a manager probe fails', function (ToolManagerName $manager): void {
        $baseline = new AddNodeRoleBaselineFake;
        $materializer = new FakeToolManagerMaterializer;
        $materializer->failure = new NodeProvisioningException(
            "tool-manager-{$manager->value}",
            'node.tool_manager_probe_failed',
            'Manager probe failed.',
            result: new CommandResult(9, 'secret', 'secret', 31, false),
        );
        app()->instance(RoleBaselineConverger::class, $baseline);
        app()->instance(ToolManagerMaterializer::class, $materializer);
        $node = add_role_node();

        expect(fn () => app(AddNodeRoleAction::class)->execute($node, RoleName::AppProd))
            ->toThrow(function (NodeRoleOperationException $exception) use ($manager): void {
                expect($exception->step)
                    ->toBe("converge:tool-manager-{$manager->value}")
                    ->and($exception->errorCode)
                    ->toBe('node_role.convergence_failed')
                    ->and($exception->underlyingErrorCode)
                    ->toBe('node.tool_manager_probe_failed')
                    ->and($exception->result?->stdout)
                    ->toBeEmpty()
                    ->and($exception->result?->stderr)
                    ->toBeEmpty();
            });

        $assignment = $node->roles()->sole();
        expect($node->refresh()->status)
            ->toBe(LifecycleStatus::Active)
            ->and($assignment->status)
            ->toBe(LifecycleStatus::Failed)
            ->and($assignment->failed_step)
            ->toBe("converge:tool-manager-{$manager->value}")
            ->and($assignment->error_code)
            ->toBe('node.tool_manager_probe_failed');
    })->with(['VP' => ToolManagerName::Vp, 'Composer' => ToolManagerName::Composer]);

    it('reactivates retained app manager records when an app role is added again', function (): void {
        $baseline = new AddNodeRoleBaselineFake;
        $vpManager = new FakeToolManager(ToolManagerName::Vp);
        $vpManager->requiresAppRole = true;
        $composerManager = new FakeToolManager(ToolManagerName::Composer);
        $composerManager->requiresAppRole = true;
        app()->instance(RoleBaselineConverger::class, $baseline);
        app()->instance(ToolManagerMaterializer::class, new NativeToolManagerMaterializer(
            new ToolManagerRegistry([
                $vpManager,
                $composerManager,
            ]),
            app(ToolManagerScopeLock::class),
        ));
        $node = add_role_node();
        $node->load('roles');
        $vp = $node->toolManagers()->create([
            'name' => ToolManagerName::Vp,
            'status' => LifecycleStatus::Failed,
            'installed_version' => '0.31.0',
            'failed_step' => 'app-role',
            'error_code' => 'tool_manager.app_role_required',
        ]);
        $composer = $node->toolManagers()->create([
            'name' => ToolManagerName::Composer,
            'status' => LifecycleStatus::Failed,
            'installed_version' => '2.7.9',
            'failed_step' => 'app-role',
            'error_code' => 'tool_manager.app_role_required',
        ]);

        $result = app(AddNodeRoleAction::class)->execute($node, RoleName::AppDev);

        expect($result['assignment']->status)
            ->toBe(LifecycleStatus::Active)
            ->and(ToolManagerRecord::query()->count())
            ->toBe(2)
            ->and($vp->refresh()->id)
            ->toBe($vp->id)
            ->and($vp->status)
            ->toBe(LifecycleStatus::Active)
            ->and($vp->installed_version)
            ->toBe('1.0.0')
            ->and($vp->failed_step)
            ->toBeNull()
            ->and($vp->error_code)
            ->toBeNull()
            ->and($composer->refresh()->id)
            ->toBe($composer->id)
            ->and($composer->status)
            ->toBe(LifecycleStatus::Active)
            ->and($composer->installed_version)
            ->toBe('1.0.0')
            ->and($composer->failed_step)
            ->toBeNull()
            ->and($composer->error_code)
            ->toBeNull();
    });
    it('creates and converges a mutable role outside a database transaction', function (): void {
        expect(class_exists(AddNodeRoleAction::class))->toBeTrue();

        $baseline = new AddNodeRoleBaselineFake;
        app()->instance(RoleBaselineConverger::class, $baseline);
        $node = add_role_node();

        $ambientTransactionLevel = DB::transactionLevel();
        $result = app(AddNodeRoleAction::class)->execute($node, RoleName::AppDev);

        expect($result['created'])
            ->toBeTrue()
            ->and($result['assignment']->status)
            ->toBe(LifecycleStatus::Active)
            ->and($baseline->convergedRoles)
            ->toBe([RoleName::AppDev])
            ->and($baseline->observedStatuses)
            ->toBe([LifecycleStatus::Provisioning])
            ->and($baseline->transactionLevels)
            ->toBe([$ambientTransactionLevel])
            ->and($node->refresh()->status)
            ->toBe(LifecycleStatus::Active);
    });

    it('rejects a mutable role when the node is not active before baseline effects', function (): void {
        $baseline = new AddNodeRoleBaselineFake;
        app()->instance(RoleBaselineConverger::class, $baseline);
        $node = add_role_node(LifecycleStatus::Provisioning);

        expect(fn () => app(AddNodeRoleAction::class)->execute($node, RoleName::AppDev))
            ->toThrow(RoleAssignmentException::class);

        expect($baseline->convergedRoles)
            ->toBeEmpty()
            ->and($node->roles()->exists())
            ->toBeFalse();
    });

    it('allows assignable roles on a provisioning node', function (): void {
        $baseline = new AddNodeRoleBaselineFake;
        app()->instance(RoleBaselineConverger::class, $baseline);
        $node = add_role_node(LifecycleStatus::Provisioning);

        $assignment = app(AddNodeRoleAction::class)->executeDuringProvisioning($node, RoleName::AppDev);

        expect($assignment->status)
            ->toBe(LifecycleStatus::Active)
            ->and($baseline->convergedRoles)
            ->toBe([RoleName::AppDev]);
    });

    it('rejects failed, removing, and nonexistent nodes during provisioning', function (?LifecycleStatus $status): void {
        $baseline = new AddNodeRoleBaselineFake;
        app()->instance(RoleBaselineConverger::class, $baseline);
        $node = $status === null ? new Node : add_role_node($status);

        expect(fn () => app(AddNodeRoleAction::class)->executeDuringProvisioning($node, RoleName::AppDev))
            ->toThrow(RoleAssignmentException::class);

        expect($baseline->convergedRoles)->toBeEmpty();
        if ($node->exists) {
            expect($node->roles()->exists())->toBeFalse();
        }
    })->with([
        'failed node' => LifecycleStatus::Failed,
        'removing node' => LifecycleStatus::Removing,
        'nonexistent node' => null,
    ]);

    it('rejects protected roles before baseline effects', function (RoleName $role): void {
        $baseline = new AddNodeRoleBaselineFake;
        $materializer = new FakeToolManagerMaterializer;
        app()->instance(RoleBaselineConverger::class, $baseline);
        app()->instance(ToolManagerMaterializer::class, $materializer);
        $node = add_role_node();

        expect(fn () => app(AddNodeRoleAction::class)->execute($node, $role))
            ->toThrow(RoleAssignmentException::class);

        expect($baseline->convergedRoles)
            ->toBeEmpty()
            ->and($materializer->requests)
            ->toBeEmpty()
            ->and($node->roles()->exists())
            ->toBeFalse();
    })->with([
        'gateway' => RoleName::Gateway,
        'VPN' => RoleName::Vpn,
    ]);

    it('rejects an existing assignment unless convergence is explicit', function (): void {
        $baseline = new AddNodeRoleBaselineFake;
        app()->instance(RoleBaselineConverger::class, $baseline);
        $node = add_role_node();
        $assignment = $node->roles()->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);

        expect(fn () => app(AddNodeRoleAction::class)->execute($node, RoleName::AppDev))
            ->toThrow(RoleAssignmentException::class);

        expect($baseline->convergedRoles)
            ->toBeEmpty()
            ->and($assignment->refresh()->status)
            ->toBe(LifecycleStatus::Active);
    });

    it('reconverges eligible existing assignments and clears old failures', function (
        LifecycleStatus $status,
        ?string $failedStep,
    ): void {
        $baseline = new AddNodeRoleBaselineFake;
        app()->instance(RoleBaselineConverger::class, $baseline);
        $node = add_role_node();
        $assignment = $node->roles()->create([
            'role' => RoleName::AppDev,
            'status' => $status,
            'failed_step' => $failedStep,
            'error_code' => $failedStep === null ? null : 'app-dev.caddy_config_failed',
        ]);

        $ambientTransactionLevel = DB::transactionLevel();
        $result = app(AddNodeRoleAction::class)->execute($node, RoleName::AppDev, convergeExisting: true);

        expect($result['created'])
            ->toBeFalse()
            ->and($result['assignment']->is($assignment))
            ->toBeTrue()
            ->and($assignment->refresh()->status)
            ->toBe(LifecycleStatus::Active)
            ->and($assignment->failed_step)
            ->toBeNull()
            ->and($assignment->error_code)
            ->toBeNull()
            ->and($baseline->transactionLevels)
            ->toBe([$ambientTransactionLevel]);
    })->with([
        'active assignment' => [LifecycleStatus::Active, null],
        'failed convergence' => [LifecycleStatus::Failed, 'converge:caddy-config'],
    ]);

    it('rejects assignments that are busy or failed during removal', function (
        LifecycleStatus $status,
        ?string $failedStep,
    ): void {
        $baseline = new AddNodeRoleBaselineFake;
        app()->instance(RoleBaselineConverger::class, $baseline);
        $node = add_role_node();
        $assignment = $node->roles()->create([
            'role' => RoleName::AppDev,
            'status' => $status,
            'failed_step' => $failedStep,
            'error_code' => $failedStep === null ? null : 'app-dev.remove_failed',
        ]);

        expect(fn () => app(AddNodeRoleAction::class)->execute($node, RoleName::AppDev, convergeExisting: true))
            ->toThrow(RoleAssignmentException::class);

        expect($baseline->convergedRoles)
            ->toBeEmpty()
            ->and($assignment->refresh()->status)
            ->toBe($status)
            ->and($assignment->failed_step)
            ->toBe($failedStep);
    })->with([
        'provisioning assignment' => [LifecycleStatus::Provisioning, null],
        'removing assignment' => [LifecycleStatus::Removing, null],
        'failed removal' => [LifecycleStatus::Failed, 'remove:caddy-config'],
    ]);

    it('rejects same-node role conflicts before baseline effects', function (): void {
        $baseline = new AddNodeRoleBaselineFake;
        app()->instance(RoleBaselineConverger::class, $baseline);
        $node = add_role_node();
        $node->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);

        expect(fn () => app(AddNodeRoleAction::class)->execute($node, RoleName::AppDev))
            ->toThrow(RoleAssignmentException::class);

        expect($baseline->convergedRoles)->toBeEmpty();
    });

    it('rechecks singleton ownership during a provisioning claim', function (): void {
        $baseline = new AddNodeRoleBaselineFake;
        app()->instance(RoleBaselineConverger::class, $baseline);
        $first = add_role_node(name: 'first');
        $second = add_role_node(name: 'second', wireguardIp: '10.44.0.3');
        $first->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);

        expect(fn () => app(AddNodeRoleAction::class)->executeDuringProvisioning($second, RoleName::Gateway))
            ->toThrow(RoleAssignmentException::class);

        expect($baseline->convergedRoles)
            ->toBeEmpty()
            ->and($second->roles()->exists())
            ->toBeFalse();
    });

    it('stores a namespaced convergence failure while the node stays active', function (): void {
        $baseline = new AddNodeRoleBaselineFake;
        $baseline->failure = new RuntimeConvergenceException(
            step: 'caddy-config',
            errorCode: 'app-dev.caddy_config_failed',
            message: 'Caddy failed.',
        );
        app()->instance(RoleBaselineConverger::class, $baseline);
        $node = add_role_node();
        $ambientTransactionLevel = DB::transactionLevel();

        expect(fn () => app(AddNodeRoleAction::class)->execute($node, RoleName::AppDev))
            ->toThrow(function (NodeRoleOperationException $exception): void {
                expect($exception->step)
                    ->toBe('converge:caddy-config')
                    ->and($exception->errorCode)
                    ->toBe('node_role.convergence_failed')
                    ->and($exception->underlyingErrorCode)
                    ->toBe('app-dev.caddy_config_failed');
            });

        $assignment = $node->roles()->sole();

        expect($node->refresh()->status)
            ->toBe(LifecycleStatus::Active)
            ->and($assignment->status)
            ->toBe(LifecycleStatus::Failed)
            ->and($assignment->failed_step)
            ->toBe('converge:caddy-config')
            ->and($assignment->error_code)
            ->toBe('app-dev.caddy_config_failed')
            ->and($baseline->transactionLevels)
            ->toBe([$ambientTransactionLevel]);
    });

    it('persists app role failure before releasing nested manager scopes', function (): void {
        $baseline = new AddNodeRoleBaselineFake;
        $baseline->failure = new RuntimeConvergenceException(
            step: 'caddy-config',
            errorCode: 'app-dev.caddy_config_failed',
            message: 'Caddy failed.',
        );
        $materializer = new FakeToolManagerMaterializer;
        $scopeLock = new StateAwareToolManagerScopeLock;
        app()->instance(RoleBaselineConverger::class, $baseline);
        app()->instance(ToolManagerMaterializer::class, $materializer);
        app()->instance(ToolManagerScopeLock::class, $scopeLock);
        $node = add_role_node();

        expect(fn () => app(AddNodeRoleAction::class)->execute($node, RoleName::AppDev))
            ->toThrow(NodeRoleOperationException::class);

        expect($scopeLock->events)
            ->toBe(['acquire:vp', 'acquire:composer', 'release:composer', 'release:vp'])
            ->and($scopeLock->releaseStates)
            ->toBe([
                [
                    'manager' => ToolManagerName::Composer,
                    'status' => LifecycleStatus::Failed,
                    'failed_step' => 'converge:caddy-config',
                    'error_code' => 'app-dev.caddy_config_failed',
                ],
                [
                    'manager' => ToolManagerName::Vp,
                    'status' => LifecycleStatus::Failed,
                    'failed_step' => 'converge:caddy-config',
                    'error_code' => 'app-dev.caddy_config_failed',
                ],
            ])
            ->and($materializer->requests)
            ->toBeEmpty();
    });
});

function add_role_node(
    LifecycleStatus $status = LifecycleStatus::Active,
    string $name = 'app-host',
    string $wireguardIp = '10.44.0.2',
): Node {
    return Node::query()->create([
        'name' => $name,
        'status' => $status,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.10',
        'user' => 'orbit',
        'wireguard_ip' => $wireguardIp,
    ]);
}

final class AddNodeRoleBaselineFake implements RoleBaselineConverger
{
    /** @var list<RoleName> */
    public array $convergedRoles = [];

    /** @var list<LifecycleStatus> */
    public array $observedStatuses = [];

    /** @var list<int> */
    public array $transactionLevels = [];

    public ?Throwable $failure = null;

    public function converge(Node $node, NodeRole $assignment): void
    {
        $this->convergedRoles[] = $assignment->role;
        $this->observedStatuses[] = $assignment->status;
        $this->transactionLevels[] = DB::transactionLevel();

        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }
    }

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void {}

    public function removeUnreachable(Node $node, NodeRole $assignment): void {}
}

/** @mago-expect lint:single-class-per-file Test-local lock records persisted assignment state at release. */
final class StateAwareToolManagerScopeLock implements ToolManagerScopeLock
{
    /** @var list<string> */
    public array $events = [];

    /** @var list<array{manager: ToolManagerName, status: LifecycleStatus, failed_step: ?string, error_code: ?string}> */
    public array $releaseStates = [];

    public function run(int $nodeId, ToolManagerName $manager, \Closure $callback): mixed
    {
        $this->events[] = 'acquire:'.$manager->value;

        try {
            return $callback();
        } finally {
            $assignment = NodeRole::query()->where('node_id', $nodeId)->sole()->refresh();
            $this->releaseStates[] = [
                'manager' => $manager,
                'status' => $assignment->status,
                'failed_step' => $assignment->failed_step,
                'error_code' => $assignment->error_code,
            ];
            $this->events[] = 'release:'.$manager->value;
        }
    }
}
