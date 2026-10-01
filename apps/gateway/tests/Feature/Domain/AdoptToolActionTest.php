<?php

declare(strict_types=1);

use App\Actions\Tools\AdoptToolAction;
use App\Actions\Tools\InstallToolAction;
use App\Data\Tools\AdoptToolData;
use App\Data\Tools\InstallToolData;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\DebianVersionNormalizer;
use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\ToolAdoptionFact;
use App\Domain\Tools\ToolInventoryPackage;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerRegistry;
use App\Domain\Tools\ToolNodeEligibility;
use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolOperationException;
use App\Domain\Tools\ToolOutcome;
use App\Domain\Tools\ToolStatus;
use App\Domain\Tools\VersionConstraint;
use App\Infrastructure\Nodes\NodeLocks;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tools\AptToolManager;
use App\Infrastructure\Tools\NativeToolManagerScopeLock;
use App\Infrastructure\Tools\NativeToolOperationLock;
use App\Infrastructure\Tools\RemoteToolCommandRunner;
use App\Models\Node;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Tests\Support\FakeToolManager;
use Tests\Support\FakeToolManagerMaterializer;
use Tests\Support\ImmediateToolOperationLock;
use Tests\Support\ToolManagerFakeSshExecutor;

describe(AdoptToolAction::class, function (): void {
    it('registers the live package under the tool and scope locks without host mutation', function (): void {
        $node = adopt_action_node();
        [$action, $manager, $lock] = adopt_tool_action();
        $manager->adoption = new ToolAdoptionFact('14.1.1', null);

        $result = $action->execute(adopt_tool_data($node, versionConstraint: '^14.0'));

        expect($result->created)->toBeTrue()
            ->and($result->outcome)->toBe(ToolOutcome::Applied)
            ->and($result->tool->status)->toBe(ToolStatus::Installed)
            ->and($result->tool->installed_version)->toBe('14.1.1')
            ->and($result->tool->version_constraint)->toBe('^14.0')
            ->and($result->tool->failed_operation)->toBeNull()
            ->and($result->tool->error_code)->toBeNull()
            ->and($result->tool->package)->toBe('jq')
            ->and($manager->calls)->toBe(['validatePackage', 'inspectForAdoption'])
            ->and($lock->runs)->toBe(1)
            ->and($lock->arguments[0]['operation'])->toBe(ToolOperation::Adopt)
            ->and(ToolManagerRecord::query()->where('status', LifecycleStatus::Active)->count())->toBe(1)
            ->and(Tool::query()->count())->toBe(1);
    });

    it('returns an installed tool unchanged and does not rewrite its recorded version', function (): void {
        $node = adopt_action_node();
        $record = adopt_action_record($node);
        $tool = adopt_action_tool($node, $record, versionConstraint: '^1.0');
        $updatedAt = $tool->updated_at?->toJSON();
        [$action, $manager, $lock] = adopt_tool_action();
        $manager->adoption = new ToolAdoptionFact('1.2.3', null);

        $result = $action->execute(adopt_tool_data($node, versionConstraint: '^1.0'));
        $tool->refresh();

        expect($result->created)->toBeFalse()
            ->and($result->outcome)->toBe(ToolOutcome::Unchanged)
            ->and($tool->installed_version)->toBe('2.4.0')
            ->and($tool->status)->toBe(ToolStatus::Installed)
            ->and($tool->updated_at?->toJSON())->toBe($updatedAt)
            ->and($lock->runs)->toBe(1)
            ->and(Tool::query()->count())->toBe(1);
    });

    it('refuses an installed tool when the live version drifts outside the constraint and leaves the row unchanged', function (): void {
        $node = adopt_action_node();
        $record = adopt_action_record($node);
        $tool = adopt_action_tool($node, $record, versionConstraint: '^1.0');
        $updatedAt = $tool->updated_at?->toJSON();
        [$action, $manager] = adopt_tool_action();
        $manager->adoption = new ToolAdoptionFact('2.0.0', null);

        $exception = adopt_tool_exception(fn () => $action->execute(adopt_tool_data($node, versionConstraint: '^1.0')));
        $tool->refresh();

        expect($exception->errorCode)->toBe('tool.installed_version_constraint_violated')
            ->and($exception->status)->toBe(409)
            ->and($exception->toolId)->toBe($tool->id)
            ->and($tool->installed_version)->toBe('2.4.0')
            ->and($tool->status)->toBe(ToolStatus::Installed)
            ->and($tool->version_constraint)->toBe('^1.0')
            ->and($tool->updated_at?->toJSON())->toBe($updatedAt)
            ->and(Tool::query()->count())->toBe(1);
    });

    it('repairs a failed tool to installed with the live version and does not mutate the host', function (): void {
        $node = adopt_action_node();
        $record = adopt_action_record($node);
        $tool = adopt_action_tool(
            $node,
            $record,
            ToolStatus::Failed,
            '^1.0',
            ToolOperation::Update,
            'tool.update_failed',
        );
        [$action, $manager] = adopt_tool_action();
        $manager->adoption = new ToolAdoptionFact('1.4.2', null);

        $result = $action->execute(adopt_tool_data($node, versionConstraint: '^1.0'));
        $tool->refresh();

        expect($result->created)->toBeFalse()
            ->and($result->outcome)->toBe(ToolOutcome::Applied)
            ->and($tool->status)->toBe(ToolStatus::Installed)
            ->and($tool->installed_version)->toBe('1.4.2')
            ->and($tool->failed_operation)->toBeNull()
            ->and($tool->error_code)->toBeNull()
            ->and($manager->calls)->not->toContain('install', 'update', 'remove', 'materialize');
    });

    it('refuses a transitional tool and leaves the row unchanged', function (ToolStatus $status): void {
        $node = adopt_action_node();
        $record = adopt_action_record($node);
        $tool = adopt_action_tool($node, $record, $status, '^1.0');
        [$action, $manager] = adopt_tool_action();
        $manager->adoption = new ToolAdoptionFact('1.2.3', null);

        $exception = adopt_tool_exception(fn () => $action->execute(adopt_tool_data($node, versionConstraint: '^1.0')));
        $tool->refresh();

        expect($exception->errorCode)->toBe('tool.state_invalid')
            ->and($exception->status)->toBe(409)
            ->and($exception->toolId)->toBe($tool->id)
            ->and($tool->status)->toBe($status)
            ->and($tool->installed_version)->toBe('2.4.0')
            ->and(Tool::query()->count())->toBe(1);
    })->with([
        'installing' => [ToolStatus::Installing],
        'updating' => [ToolStatus::Updating],
        'removing' => [ToolStatus::Removing],
    ]);

    it('conflicts when the stored constraint differs and creates no second row', function (): void {
        $node = adopt_action_node();
        $record = adopt_action_record($node);
        $tool = adopt_action_tool($node, $record, versionConstraint: '^1.0');
        [$action] = adopt_tool_action();

        $exception = adopt_tool_exception(fn () => $action->execute(adopt_tool_data($node, versionConstraint: '^2.0')));

        expect($exception->errorCode)->toBe('tool.constraint_conflict')
            ->and($exception->toolId)->toBe($tool->id)
            ->and($tool->refresh()->version_constraint)->toBe('^1.0')
            ->and(Tool::query()->count())->toBe(1);
    });

    it('creates no intent for an absent, unsupported, unverifiable, or unavailable package', function (
        ?ToolAdoptionFact $fact,
        ?Throwable $failure,
        string $code,
        ?string $block,
    ): void {
        $node = adopt_action_node();
        [$action, $manager] = adopt_tool_action();
        $manager->adoption = $fact;
        $manager->adoptionFailure = $failure;

        $exception = adopt_tool_exception(fn () => $action->execute(adopt_tool_data($node)));

        expect($exception->errorCode)->toBe($code)
            ->and($exception->status)->toBe(409)
            ->and($exception->toolId)->toBeNull()
            ->and($exception->adoptionBlock)->toBe($block)
            ->and(Tool::query()->count())->toBe(0)
            ->and(ToolManagerRecord::query()->count())->toBe(0);
    })->with([
        'absent' => [new ToolAdoptionFact(null, null), null, 'tool.package_absent', null],
        'protected' => [new ToolAdoptionFact('1.0.0', ToolInventoryPackage::BLOCK_PROTECTED), null, 'tool.adoption_unsupported', 'protected'],
        'dependency' => [new ToolAdoptionFact('1.0.0', ToolInventoryPackage::BLOCK_DEPENDENCY), null, 'tool.adoption_unsupported', 'dependency'],
        'bottle' => [new ToolAdoptionFact('1.0.0', ToolInventoryPackage::BLOCK_BOTTLE), null, 'tool.adoption_unsupported', 'bottle_unavailable'],
        'unverifiable' => [null, new ToolManagerException('installed-version', 'unreadable'), 'tool.version_probe_failed', null],
        'scope absent' => [null, new ToolManagerException('manager-absent', 'absent'), 'tool.manager_unavailable', null],
        'scope conflict' => [null, new ToolManagerException('manager-conflict', 'conflict'), 'tool.manager_unavailable', null],
    ]);

    it('rejects a failed manager record without repairing it or creating a tool', function (): void {
        $node = adopt_action_node();
        $record = adopt_action_record($node, status: LifecycleStatus::Failed);
        [$action, $manager] = adopt_tool_action();

        $exception = adopt_tool_exception(fn () => $action->execute(adopt_tool_data($node)));

        expect($exception->errorCode)->toBe('tool.manager_unavailable')
            ->and($exception->toolId)->toBeNull()
            ->and($manager->calls)->toBe(['validatePackage'])
            ->and($record->refresh()->status)->toBe(LifecycleStatus::Failed)
            ->and(Tool::query()->count())->toBe(0);
    });

    it('does not adopt a version outside the constraint or an unreadable constrained version', function (
        string $version,
        string $code,
    ): void {
        $node = adopt_action_node();
        [$action, $manager] = adopt_tool_action();
        $manager->adoption = new ToolAdoptionFact($version, null);

        $exception = adopt_tool_exception(fn () => $action->execute(adopt_tool_data($node, versionConstraint: '^1.0')));

        expect($exception->errorCode)->toBe($code)
            ->and($exception->toolId)->toBeNull()
            ->and(Tool::query()->count())->toBe(0);
    })->with([
        'outside range' => ['2.0.0', 'tool.installed_version_constraint_violated'],
        'not semver' => ['release-2.4', 'tool.installed_version_unparseable'],
    ]);

    it('rejects an unsupported platform before the lock or any probe', function (): void {
        $node = adopt_action_node();
        $node->update(['platform' => 'macos']);
        $ssh = new ToolManagerFakeSshExecutor([]);
        $manager = new AptToolManager(
            commands: new RemoteToolCommandRunner($ssh, adopt_tool_keys(), adopt_tool_known_hosts()),
            versions: new DebianVersionNormalizer(new SemverVersionNormalizer),
        );
        $lock = new ImmediateToolOperationLock;
        $action = new AdoptToolAction(
            managers: new ToolManagerRegistry([$manager]),
            constraints: new VersionConstraint,
            lock: $lock,
            eligibility: new ToolNodeEligibility,
        );

        $exception = adopt_tool_exception(fn () => $action->execute(adopt_tool_data($node->refresh())));

        expect($exception->errorCode)->toBe('tool.manager_unsupported')
            ->and($exception->status)->toBe(422)
            ->and($lock->runs)->toBe(0)
            ->and($ssh->commands)->toBe([])
            ->and(Tool::query()->count())->toBe(0);
    });

    it('refuses a busy tool lock and a busy shared scope without creating intent', function (
        ToolManagerName $heldManager,
        ToolManagerName $requested,
    ): void {
        $node = adopt_action_node();
        $node->update(['platform' => 'macos']);
        $manager = new FakeToolManager($requested);
        $manager->adoption = new ToolAdoptionFact('1.2.3', null);
        $action = new AdoptToolAction(
            managers: new ToolManagerRegistry([$manager]),
            constraints: new VersionConstraint,
            lock: new NativeToolOperationLock(new NativeToolManagerScopeLock),
            eligibility: new ToolNodeEligibility,
        );
        $held = new NativeToolOperationLock(new NativeToolManagerScopeLock);

        $held->run($node->id, $heldManager, 'jq', ToolOperation::Install, null, function () use ($action, $node, $requested): void {
            $exception = adopt_tool_exception(fn () => $action->execute(adopt_tool_data($node, $requested)));

            expect($exception->errorCode)->toBe('tool.operation_locked')
                ->and($exception->step)->toBe('adopt')
                ->and($exception->toolId)->toBeNull();
        });

        expect(Tool::query()->count())->toBe(0)
            ->and($manager->calls)->toBe(['validatePackage']);
    })->with([
        'same identity as install' => [ToolManagerName::Apt, ToolManagerName::Apt],
        'cask shares the brew prefix lock' => [ToolManagerName::Brew, ToolManagerName::BrewCask],
    ]);

    it('does not duplicate ownership when install and adopt race through the same identity', function (): void {
        $node = adopt_action_node();
        $manager = new FakeToolManager;
        $manager->installedVersions = ['1.7.1', '1.7.1'];
        $manager->adoption = new ToolAdoptionFact('1.7.1', null);
        $registry = new ToolManagerRegistry([$manager]);
        $lock = new ImmediateToolOperationLock;
        $materializer = new FakeToolManagerMaterializer;
        $materializer->persistActive = true;
        $install = new InstallToolAction($registry, new VersionConstraint, $lock, $materializer, new ToolNodeEligibility);
        $adopt = new AdoptToolAction($registry, new VersionConstraint, $lock, new ToolNodeEligibility);

        $exception = adopt_install_exception(fn () => $install->execute(adopt_install_data($node)));
        $adopted = $adopt->execute(adopt_tool_data($node));
        $again = $install->execute(adopt_install_data($node));

        expect($exception->errorCode)->toBe('tool.already_installed_unmanaged')
            ->and($adopted->created)->toBeTrue()
            ->and($again->created)->toBeFalse()
            ->and($again->tool->is($adopted->tool))->toBeTrue()
            ->and(Tool::query()->count())->toBe(1)
            ->and($manager->calls)->not->toContain('materialize');
    });

    it('keeps formula and cask ownership distinct for the same package name', function (): void {
        $node = adopt_action_node();
        $node->update(['platform' => 'macos']);
        $brew = new FakeToolManager(ToolManagerName::Brew);
        $cask = new FakeToolManager(ToolManagerName::BrewCask);
        $brew->adoption = new ToolAdoptionFact('28.0.0', null);
        $cask->adoption = new ToolAdoptionFact('4.39.0', null);
        $action = new AdoptToolAction(
            managers: new ToolManagerRegistry([$brew, $cask]),
            constraints: new VersionConstraint,
            lock: new ImmediateToolOperationLock,
            eligibility: new ToolNodeEligibility,
        );

        $formula = $action->execute(adopt_tool_data($node, ToolManagerName::Brew, 'docker'));
        $caskTool = $action->execute(adopt_tool_data($node, ToolManagerName::BrewCask, 'docker'));

        expect($formula->tool->id)->not->toBe($caskTool->tool->id)
            ->and($formula->tool->manager->name)->toBe('brew')
            ->and($caskTool->tool->manager->name)->toBe('brew-cask')
            ->and($formula->tool->installed_version)->toBe('28.0.0')
            ->and($caskTool->tool->installed_version)->toBe('4.39.0')
            ->and(Tool::query()->count())->toBe(2);
    });

    it('releases a busy lock without leaving a cache lock behind', function (): void {
        $nodeId = 7;
        $lock = new NativeToolOperationLock(new NativeToolManagerScopeLock);
        $locks = app(NodeLocks::class);
        $identity = $locks->lock('tool:'.$nodeId.':apt:'.hash('sha256', 'jq'), 30);
        expect($identity->get())->toBeTrue();

        try {
            expect(fn () => $lock->run(
                nodeId: $nodeId,
                manager: ToolManagerName::Apt,
                package: 'jq',
                operation: ToolOperation::Adopt,
                versionConstraint: null,
                callback: static fn (): bool => true,
            ))->toThrow(ToolOperationException::class);
        } finally {
            $identity->release();
        }

        $released = $locks->lock('tool:'.$nodeId.':apt:'.hash('sha256', 'jq'), 30);
        expect($released->get())->toBeTrue();
        $released->release();
    });
});

/**
 * @return array{AdoptToolAction, FakeToolManager, ImmediateToolOperationLock}
 */
function adopt_tool_action(ToolManagerName $name = ToolManagerName::Apt): array
{
    $manager = new FakeToolManager($name);
    $lock = new ImmediateToolOperationLock;

    return [
        new AdoptToolAction(
            managers: new ToolManagerRegistry([$manager]),
            constraints: new VersionConstraint,
            lock: $lock,
            eligibility: new ToolNodeEligibility,
        ),
        $manager,
        $lock,
    ];
}

function adopt_tool_data(
    Node $node,
    ToolManagerName $manager = ToolManagerName::Apt,
    string $package = 'jq',
    ?string $versionConstraint = null,
): AdoptToolData {
    return new AdoptToolData($node->id, $manager->value, $package, $versionConstraint);
}

function adopt_tool_exception(Closure $callback): ToolOperationException
{
    try {
        $callback();
    } catch (ToolOperationException $exception) {
        expect($exception->step)->toBe(ToolOperation::Adopt->value);

        return $exception;
    }

    throw new RuntimeException('Expected a ToolOperationException.');
}

function adopt_action_node(): Node
{
    return Node::query()->create([
        'name' => fake()->unique()->slug(2),
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => fake()->unique()->ipv4(),
        'wireguard_ip' => fake()->unique()->ipv4(),
        'ssh_host_fingerprint' => 'SHA256:'.str_repeat('A', times: 43),
    ]);
}

function adopt_action_record(
    Node $node,
    ToolManagerName $name = ToolManagerName::Apt,
    LifecycleStatus $status = LifecycleStatus::Active,
): ToolManagerRecord {
    return $node->toolManagers()->create([
        'name' => $name,
        'status' => $status,
    ]);
}

function adopt_action_tool(
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

function adopt_install_data(Node $node): InstallToolData
{
    return new InstallToolData($node->id, ToolManagerName::Apt->value, 'jq', null);
}

function adopt_install_exception(Closure $callback): ToolOperationException
{
    try {
        $callback();
    } catch (ToolOperationException $exception) {
        expect($exception->step)->toBe(ToolOperation::Install->value);

        return $exception;
    }

    throw new RuntimeException('Expected a ToolOperationException.');
}

function adopt_tool_keys(): SshKeyProvider
{
    return new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/tmp/orbit-test-key';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 AAAATEST orbit@test';
        }
    };
}

function adopt_tool_known_hosts(): KnownHostsStore
{
    return new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/tmp/orbit-test-known-hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
}
