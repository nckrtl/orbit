<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManager;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerMaterializer;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerRegistry;
use App\Domain\Tools\ToolManagerScopeLock;
use App\Domain\Tools\ToolManagerScopeLockException;
use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolRemovalPlan;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Tools\NativeToolManagerMaterializer;
use App\Infrastructure\Tools\NativeToolManagerScopeLock;
use App\Models\Node;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Illuminate\Support\Facades\Cache;

/** @mago-expect lint:halstead The materializer lifecycle group keeps lock, probe, retry, and failure invariants visible. */
describe(NativeToolManagerMaterializer::class, function (): void {
    it('is the production Tool manager materializer', function (): void {
        expect(app(ToolManagerMaterializer::class))
            ->toBeInstanceOf(NativeToolManagerMaterializer::class)
            ->and(app(ToolManagerScopeLock::class))
            ->toBeInstanceOf(NativeToolManagerScopeLock::class);
    });

    it('materializes supported managers in registry order', function (?RoleName $role, array $expected): void {
        $node = materializer_node($role);
        $events = [];
        $apt = new MaterializerToolManagerFake(ToolManagerName::Apt, $events, 'apt 3.0');
        $vp = new MaterializerToolManagerFake(ToolManagerName::Vp, $events, '0.32.0');
        $composer = new MaterializerToolManagerFake(ToolManagerName::Composer, $events, '2.8.10');
        $vp->supports = $role !== null;
        $composer->supports = $role !== null;

        new NativeToolManagerMaterializer(
            new ToolManagerRegistry([$apt, $vp, $composer]),
            new NativeToolManagerScopeLock,
        )->converge($node);

        expect($events)
            ->toBe($expected)
            ->and(ToolManagerRecord::query()->where('node_id', $node->id)->orderBy('id')->pluck('name')->all())
            ->toBe(array_map(ToolManagerName::from(...), $expected))
            ->and(Tool::query()->count())
            ->toBe(0);
    })->with([
        'roleless Linux' => [null, ['apt']],
        'app-dev Linux' => [RoleName::AppDev, ['apt', 'vp', 'composer']],
        'app-prod Linux' => [RoleName::AppProd, ['apt', 'vp', 'composer']],
    ]);

    it('materializes only explicitly selected managers', function (): void {
        $node = materializer_node();
        $events = [];
        $apt = new MaterializerToolManagerFake(ToolManagerName::Apt, $events, 'apt 3.0');
        $vp = new MaterializerToolManagerFake(ToolManagerName::Vp, $events, '0.32.0');

        new NativeToolManagerMaterializer(
            new ToolManagerRegistry([$apt, $vp]),
            new NativeToolManagerScopeLock,
        )->converge($node, ToolManagerName::Apt);

        expect($events)
            ->toBe(['apt'])
            ->and(ToolManagerRecord::query()->pluck('name')->all())
            ->toBe([ToolManagerName::Apt]);
    });

    it('shares the native manager scope with tool operations', function (): void {
        $node = materializer_node();
        $events = [];
        $manager = new MaterializerToolManagerFake(ToolManagerName::Vp, $events, '0.32.0');
        $scope = Cache::lock("orbit:tool-manager:{$node->id}:vp", 3_600);
        expect($scope->get())->toBeTrue();

        try {
            expect(
                fn () => new NativeToolManagerMaterializer(
                    new ToolManagerRegistry([$manager]),
                    new NativeToolManagerScopeLock,
                )->converge(
                    $node,
                    ToolManagerName::Vp,
                ),
            )
                ->toThrow(NodeProvisioningException::class);
        } finally {
            $scope->release();
        }

        expect($events)
            ->toBeEmpty()
            ->and(ToolManagerRecord::query()->count())
            ->toBe(0);
    });

    it('acquires every requested scope before probing any manager', function (): void {
        $node = materializer_node(RoleName::AppDev);
        $events = [];
        $vp = new MaterializerToolManagerFake(ToolManagerName::Vp, $events, '0.32.0');
        $composer = new MaterializerToolManagerFake(ToolManagerName::Composer, $events, '2.8.10');
        $scope = Cache::lock("orbit:tool-manager:{$node->id}:composer", 3_600);
        expect($scope->get())->toBeTrue();

        try {
            expect(
                fn () => new NativeToolManagerMaterializer(
                    new ToolManagerRegistry([$vp, $composer]),
                    new NativeToolManagerScopeLock,
                )->converge($node, ToolManagerName::Vp, ToolManagerName::Composer),
            )
                ->toThrow(function (NodeProvisioningException $exception): void {
                    expect($exception->step)
                        ->toBe('tool-manager-composer')
                        ->and($exception->errorCode)
                        ->toBe('node.tool_manager_locked');
                });
        } finally {
            $scope->release();
        }

        expect($events)
            ->toBeEmpty()
            ->and(ToolManagerRecord::query()->count())
            ->toBe(0);
    });

    it('acquires both app scopes before a single app manager probe', function (): void {
        $node = materializer_node(RoleName::AppDev, LifecycleStatus::Provisioning);
        $events = [];
        $vp = new MaterializerToolManagerFake(ToolManagerName::Vp, $events, '0.32.0');
        $scope = Cache::lock("orbit:tool-manager:{$node->id}:composer", 3_600);
        expect($scope->get())->toBeTrue();

        try {
            expect(
                fn () => new NativeToolManagerMaterializer(
                    new ToolManagerRegistry([$vp]),
                    new NativeToolManagerScopeLock,
                )->converge($node, ToolManagerName::Vp),
            )
                ->toThrow(function (NodeProvisioningException $exception): void {
                    expect($exception->step)
                        ->toBe('tool-manager-composer')
                        ->and($exception->errorCode)
                        ->toBe('node.tool_manager_locked');
                });
        } finally {
            $scope->release();
        }

        expect($events)
            ->toBeEmpty()
            ->and(ToolManagerRecord::query()->count())
            ->toBe(0);
    });

    it('runs the role failure transition before releasing app scopes', function (): void {
        $node = materializer_node(RoleName::AppDev, LifecycleStatus::Provisioning);
        $events = [];
        $vp = new MaterializerToolManagerFake(ToolManagerName::Vp, $events, '0.32.0');
        $vp->failure = new ToolManagerException('manager-version', 'failed');
        $lock = new OrderingMaterializerScopeLock($events);
        $materializer = new NativeToolManagerMaterializer(
            new ToolManagerRegistry([$vp]),
            $lock,
        );

        expect(fn () => $materializer->convergeWithFailureHandler(
            $node,
            static function (NodeProvisioningException $failure) use ($lock): void {
                $lock->record('failure:'.$failure->step);
            },
            ToolManagerName::Vp,
        ))
            ->toThrow(NodeProvisioningException::class);

        expect($events)->toBe([
            'enter:vp',
            'enter:composer',
            'vp',
            'failure:tool-manager-vp',
            'release:composer',
            'release:vp',
        ]);
    });

    it('keeps nested app scopes owned and held until the failure callback returns', function (): void {
        $node = materializer_node(RoleName::AppDev, LifecycleStatus::Provisioning);
        $role = $node->roles()->sole();
        $events = [];
        $vp = new MaterializerToolManagerFake(ToolManagerName::Vp, $events, '0.32.0');
        $composer = new MaterializerToolManagerFake(ToolManagerName::Composer, $events, '2.8.10');
        $vp->failure = new ToolManagerException('manager-version', 'VP failed.');
        $scope = new NativeToolManagerScopeLock;
        $materializer = new NativeToolManagerMaterializer(
            new ToolManagerRegistry([$vp, $composer]),
            $scope,
        );
        $callbackFailure = null;
        $materializationFailure = null;
        $vpContended = false;
        $composerContended = false;

        try {
            $scope->run($node->id, ToolManagerName::Vp, function () use (
                $node,
                $scope,
                $materializer,
                $role,
                &$callbackFailure,
                &$vpContended,
                &$composerContended,
            ): void {
                $scope->run($node->id, ToolManagerName::Composer, function () use (
                    $node,
                    $materializer,
                    $role,
                    &$callbackFailure,
                    &$vpContended,
                    &$composerContended,
                ): void {
                    $materializer->convergeWithFailureHandler(
                        $node,
                        function (NodeProvisioningException $failure) use (
                            $role,
                            $node,
                            &$callbackFailure,
                            &$vpContended,
                            &$composerContended,
                        ): void {
                            $callbackFailure = $failure;
                            $role->markConvergenceFailed($failure->step, $failure->errorCode);

                            $vpLock = new NativeToolManagerScopeLock;
                            $composerLock = new NativeToolManagerScopeLock;
                            $vpProbe = Cache::lock("orbit:tool-manager:{$node->id}:vp", 3_600);
                            $composerProbe = Cache::lock("orbit:tool-manager:{$node->id}:composer", 3_600);
                            expect($vpProbe->get())->toBeFalse()->and($composerProbe->get())->toBeFalse();
                            try {
                                $vpLock->run($node->id, ToolManagerName::Vp, static fn (): true => true);
                            } catch (ToolManagerScopeLockException) {
                                $vpContended = true;
                            }
                            try {
                                $composerLock->run($node->id, ToolManagerName::Composer, static fn (): true => true);
                            } catch (ToolManagerScopeLockException) {
                                $composerContended = true;
                            }
                            $vpProbe->release();
                            $composerProbe->release();
                        },
                        ToolManagerName::Vp,
                        ToolManagerName::Composer,
                    );
                });
            });
        } catch (NodeProvisioningException $failure) {
            $materializationFailure = $failure;
        }

        expect($materializationFailure)
            ->toBeInstanceOf(NodeProvisioningException::class)
            ->and($callbackFailure)
            ->toBeInstanceOf(NodeProvisioningException::class)
            ->and($callbackFailure?->step)
            ->toBe('tool-manager-vp')
            ->and($callbackFailure?->errorCode)
            ->toBe('node.tool_manager_probe_failed')
            ->and($role->refresh()->status)
            ->toBe(LifecycleStatus::Failed)
            ->and($role->failed_step)
            ->toBe('converge:tool-manager-vp')
            ->and($role->error_code)
            ->toBe('node.tool_manager_probe_failed')
            ->and($vpContended)
            ->toBeTrue()
            ->and($composerContended)
            ->toBeTrue()
            ->and($events)
            ->toBe(['vp']);

        $releasedVp = new NativeToolManagerScopeLock;
        $releasedComposer = new NativeToolManagerScopeLock;
        expect($releasedVp->run($node->id, ToolManagerName::Vp, static fn (): true => true))
            ->toBeTrue()
            ->and($releasedComposer->run($node->id, ToolManagerName::Composer, static fn (): true => true))
            ->toBeTrue();
    });

    it('retires a successful app manager when a later probe fails for the sole provisioning app role', function (): void {
        $node = materializer_node(RoleName::AppDev, LifecycleStatus::Provisioning);
        $events = [];
        $vp = new MaterializerToolManagerFake(ToolManagerName::Vp, $events, '0.32.0');
        $composer = new MaterializerToolManagerFake(ToolManagerName::Composer, $events, 'unused');
        $composer->failure = new ToolManagerException('manager-version', 'Composer failed.');
        $vpRecord = $node->toolManagers()->create([
            'name' => ToolManagerName::Vp,
            'status' => LifecycleStatus::Failed,
            'installed_version' => '0.31.0',
        ]);
        $composerRecord = $node->toolManagers()->create([
            'name' => ToolManagerName::Composer,
            'status' => LifecycleStatus::Active,
            'installed_version' => '2.7.9',
        ]);

        expect(
            fn () => new NativeToolManagerMaterializer(
                new ToolManagerRegistry([$vp, $composer]),
                new NativeToolManagerScopeLock,
            )->converge($node, ToolManagerName::Vp, ToolManagerName::Composer),
        )
            ->toThrow(NodeProvisioningException::class);

        expect($events)
            ->toBe(['vp', 'composer'])
            ->and($vpRecord->refresh()->status)
            ->toBe(LifecycleStatus::Failed)
            ->and($vpRecord->installed_version)
            ->toBe('0.32.0')
            ->and($vpRecord->failed_step)
            ->toBe('app-role')
            ->and($vpRecord->error_code)
            ->toBe('tool_manager.app_role_required')
            ->and($composerRecord->refresh()->status)
            ->toBe(LifecycleStatus::Failed)
            ->and($composerRecord->installed_version)
            ->toBe('2.7.9')
            ->and($composerRecord->failed_step)
            ->toBe('manager-version')
            ->and($composerRecord->error_code)
            ->toBe('node.tool_manager_probe_failed');

        $released = Cache::lock("orbit:tool-manager:{$node->id}:vp", 3_600);
        expect($released->get())->toBeTrue();
        $released->release();
    });

    it('preserves a healthy app manager when another active app role remains', function (): void {
        $node = materializer_node(RoleName::AppDev, LifecycleStatus::Provisioning);
        $node->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
        $events = [];
        $vp = new MaterializerToolManagerFake(ToolManagerName::Vp, $events, '0.32.0');
        $composer = new MaterializerToolManagerFake(ToolManagerName::Composer, $events, 'unused');
        $composer->failure = new ToolManagerException('manager-version', 'Composer failed.');

        expect(
            fn () => new NativeToolManagerMaterializer(
                new ToolManagerRegistry([$vp, $composer]),
                new NativeToolManagerScopeLock,
            )->converge($node, ToolManagerName::Vp, ToolManagerName::Composer),
        )
            ->toThrow(NodeProvisioningException::class);

        expect($node->toolManagers()->where('name', ToolManagerName::Vp)->sole()->status)
            ->toBe(LifecycleStatus::Active)
            ->and($node->toolManagers()->where('name', ToolManagerName::Vp)->sole()->installed_version)
            ->toBe('0.32.0');
    });

    it('reuses records and clears retained failures after retry', function (): void {
        $node = materializer_node();
        $events = [];
        $manager = new MaterializerToolManagerFake(ToolManagerName::Apt, $events, 'apt 3.0');
        $materializer = new NativeToolManagerMaterializer(
            new ToolManagerRegistry([$manager]),
            new NativeToolManagerScopeLock,
        );
        $materializer->converge($node);
        $record = ToolManagerRecord::query()->sole();
        $record->update([
            'status' => LifecycleStatus::Failed,
            'failed_step' => 'manager-version',
            'error_code' => 'node.tool_manager_probe_failed',
        ]);
        $manager->version = 'apt 3.1';

        $materializer->converge($node, ToolManagerName::Apt);

        expect(ToolManagerRecord::query()->count())
            ->toBe(1)
            ->and(ToolManagerRecord::query()->sole()->id)
            ->toBe($record->id)
            ->and($record->refresh()->status)
            ->toBe(LifecycleStatus::Active)
            ->and($record->installed_version)
            ->toBe('apt 3.1')
            ->and($record->failed_step)
            ->toBeNull()
            ->and($record->error_code)
            ->toBeNull();
    });

    it('persists a safe failure and preserves the prior version', function (): void {
        $node = materializer_node();
        $record = ToolManagerRecord::query()->create([
            'node_id' => $node->id,
            'name' => ToolManagerName::Apt,
            'status' => LifecycleStatus::Active,
            'installed_version' => 'apt 2.9',
        ]);
        $events = [];
        $manager = new MaterializerToolManagerFake(ToolManagerName::Apt, $events, 'unused');
        $manager->failure = new ToolManagerException(
            'manager-version',
            'secret remote stderr',
            new CommandResult(17, 'secret stdout', 'secret stderr', 41, true),
        );

        expect(
            fn () => new NativeToolManagerMaterializer(
                new ToolManagerRegistry([$manager]),
                new NativeToolManagerScopeLock,
            )->converge(
                $node,
                ToolManagerName::Apt,
            ),
        )
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)
                    ->toBe('tool-manager-apt')
                    ->and($exception->errorCode)
                    ->toBe('node.tool_manager_probe_failed')
                    ->and($exception->getMessage())
                    ->not
                    ->toContain('secret')
                    ->and($exception->result?->exitCode)
                    ->toBe(17)
                    ->and($exception->result?->stdout)
                    ->toBeEmpty()
                    ->and($exception->result?->stderr)
                    ->toBeEmpty();
            });

        expect($record->refresh()->status)
            ->toBe(LifecycleStatus::Failed)
            ->and($record->installed_version)
            ->toBe('apt 2.9')
            ->and($record->failed_step)
            ->toBe('manager-version')
            ->and($record->error_code)
            ->toBe('node.tool_manager_probe_failed')
            ->and(Tool::query()->count())
            ->toBe(0);
    });
});

function materializer_node(
    ?RoleName $role = null,
    LifecycleStatus $roleStatus = LifecycleStatus::Active,
): Node {
    $node = Node::query()->create([
        'name' => fake()->unique()->domainWord(),
        'status' => LifecycleStatus::Provisioning,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => fake()->ipv4(),
        'wireguard_ip' => fake()->unique()->ipv4(),
    ]);

    if ($role !== null) {
        $node->roles()->create(['role' => $role, 'status' => $roleStatus]);
    }

    return $node;
}

/** @mago-expect lint:too-many-methods The focused fake implements the complete manager contract for lifecycle tests. */
final class MaterializerToolManagerFake implements ToolManager
{
    public bool $supports = true;
    public ?ToolManagerException $failure = null;

    /** @param list<string> $events */
    public function __construct(
        private readonly ToolManagerName $managerName,
        private array &$events,
        public string $version,
    ) {}

    public function name(): ToolManagerName
    {
        return $this->managerName;
    }

    public function supportsNode(Node $node): bool
    {
        return $this->supports;
    }

    public function validatePackage(string $package): bool
    {
        return true;
    }

    public function managerVersion(Node $node): string
    {
        $this->events[] = $this->managerName->value;
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->version;
    }

    public function candidateVersion(Node $node, string $package, ToolOperation $operation): ?string
    {
        return null;
    }

    public function installedVersion(Node $node, string $package): ?string
    {
        return null;
    }

    public function normalizeVersion(string $rawVersion): ?string
    {
        return null;
    }

    public function install(Node $node, string $package): void {}

    public function update(Node $node, string $package): void {}

    public function planRemoval(Node $node, string $package): ToolRemovalPlan
    {
        return new ToolRemovalPlan([]);
    }

    public function remove(Node $node, string $package): void {}
}

/** @mago-expect lint:single-class-per-file Test-local lock records canonical acquisition and release order. */
final class OrderingMaterializerScopeLock implements ToolManagerScopeLock
{
    public function __construct(
        private array &$events,
    ) {}

    public function record(string $event): void
    {
        $this->events[] = $event;
    }

    public function run(int $nodeId, ToolManagerName $manager, \Closure $callback): mixed
    {
        $this->events[] = 'enter:'.$manager->value;
        try {
            return $callback();
        } finally {
            $this->events[] = 'release:'.$manager->value;
        }
    }
}
