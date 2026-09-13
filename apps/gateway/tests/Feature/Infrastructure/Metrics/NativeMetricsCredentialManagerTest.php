<?php

declare(strict_types=1);

use App\Domain\Metrics\MetricsCredentialOperationLock;
use App\Domain\Metrics\MetricsCredentialRuntime;
use App\Domain\Nodes\RoleName;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\NativeMetricsCredentialManager;
use App\Infrastructure\Metrics\NativeMetricsCredentialOperationLock;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Node;
use App\Models\Setting;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

afterEach(function (): void {
    Str::createRandomStringsNormally();
});

describe(NativeMetricsCredentialManager::class, function (): void {
    it('creates one encrypted password and reuses it during convergence', function (): void {
        Str::createRandomStringsUsing(static fn (int $length): string => str_repeat('a', $length));
        $node = metricsCredentialNode();
        $manager = new NativeMetricsCredentialManager(
            app(SettingRepository::class),
            new MetricsCredentialRuntimeFake,
            app(MetricsCredentialOperationLock::class),
        );

        $first = $manager->passwordForConvergence($node);
        $second = $manager->passwordForConvergence($node);
        $stored = Setting::query()
            ->where('scope_type', SettingScopeType::Node->value)
            ->where('scope_id', $node->id)
            ->where('key', NativeMetricsCredentialManager::ActivePasswordKey)
            ->sole();

        expect($first)
            ->toBe(str_repeat('a', 32))
            ->and($second)
            ->toBe($first)
            ->and($stored->is_secret)
            ->toBeTrue()
            ->and($stored->value)
            ->not->toBe($first);
    });

    it('returns only an active password that Grafana verifies', function (): void {
        Str::createRandomStringsUsing(static fn (int $length): string => str_repeat('v', $length));
        $activePassword = Str::random(32);
        $node = metricsCredentialNode();
        $runtime = new MetricsCredentialRuntimeFake;
        $manager = new NativeMetricsCredentialManager(
            app(SettingRepository::class),
            $runtime,
            app(MetricsCredentialOperationLock::class),
        );
        metricsCredentialSettings()->put(
            metricsCredentialScope($node),
            NativeMetricsCredentialManager::ActivePasswordKey,
            $activePassword,
            SettingValueProtection::Secret,
        );

        $credentials = $manager->credentials();

        expect($credentials->toArray())
            ->toBe([
                'url' => 'https://metrics.orbit',
                'username' => 'admin',
                'password' => $activePassword,
            ])
            ->and($runtime->verified)
            ->toBe([[$node->id, $activePassword]]);
    });

    it('preserves and reuses a pending password until reset verification succeeds', function (): void {
        Str::createRandomStringsUsing(static fn (int $length): string => str_repeat('p', $length));
        $node = metricsCredentialNode();
        $runtime = new MetricsCredentialRuntimeFake;
        $runtime->accepts = false;
        $manager = new NativeMetricsCredentialManager(
            app(SettingRepository::class),
            $runtime,
            app(MetricsCredentialOperationLock::class),
        );
        metricsCredentialSettings()->put(
            metricsCredentialScope($node),
            NativeMetricsCredentialManager::ActivePasswordKey,
            'old-active-password',
            SettingValueProtection::Secret,
        );

        try {
            $manager->reset();
            $failure = null;
        } catch (ResourceOperationException $exception) {
            $failure = $exception;
        }

        $pending = str_repeat('p', 32);
        expect($failure)
            ->toBeInstanceOf(ResourceOperationException::class)
            ->and($failure?->errorCode)
            ->toBe('metrics.credentials_reset_unverified')
            ->and($failure?->getMessage())
            ->not
            ->toContain($pending)
            ->and(metricsCredentialSettings()->get(
                metricsCredentialScope($node),
                NativeMetricsCredentialManager::PendingPasswordKey,
            ))
            ->toBe($pending)
            ->and(metricsCredentialSettings()->get(
                metricsCredentialScope($node),
                NativeMetricsCredentialManager::ActivePasswordKey,
            ))
            ->toBe('old-active-password');

        $runtime->accepts = true;
        $credentials = $manager->reset();

        expect($credentials->password)
            ->toBe($pending)
            ->and($runtime->applied)
            ->toBe([[$node->id, 'old-active-password', $pending]])
            ->and(metricsCredentialSettings()->get(
                metricsCredentialScope($node),
                NativeMetricsCredentialManager::ActivePasswordKey,
            ))
            ->toBe($pending)
            ->and(metricsCredentialSettings()->get(
                metricsCredentialScope($node),
                NativeMetricsCredentialManager::PendingPasswordKey,
            ))
            ->toBeNull();
    });

    it('applies a pending password Grafana never received before promoting it', function (): void {
        $node = metricsCredentialNode();
        $runtime = new MetricsCredentialRuntimeFake;
        // Grafana still holds the active password: the earlier reset stored the
        // pending one and failed before applying it.
        $runtime->holds('old-active-password');
        $manager = new NativeMetricsCredentialManager(
            app(SettingRepository::class),
            $runtime,
            app(MetricsCredentialOperationLock::class),
        );
        $scope = metricsCredentialScope($node);
        $settings = metricsCredentialSettings();
        $settings->put(
            $scope,
            NativeMetricsCredentialManager::ActivePasswordKey,
            'old-active-password',
            SettingValueProtection::Secret,
        );
        $settings->put(
            $scope,
            NativeMetricsCredentialManager::PendingPasswordKey,
            'pending-password',
            SettingValueProtection::Secret,
        );

        $credentials = $manager->reset();

        expect($runtime->applied)
            ->toBe([[$node->id, 'old-active-password', 'pending-password']])
            ->and($runtime->held())
            ->toBe('pending-password')
            ->and($credentials->password)
            ->toBe('pending-password')
            ->and($settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey))
            ->toBe('pending-password')
            ->and($settings->get($scope, NativeMetricsCredentialManager::PendingPasswordKey))
            ->toBeNull();
    });

    it('promotes a pending password Grafana already holds without applying it again', function (): void {
        $node = metricsCredentialNode();
        $runtime = new MetricsCredentialRuntimeFake;
        // The earlier reset applied the pending password and failed before
        // promoting it, so Grafana already answers to it.
        $runtime->holds('pending-password');
        $manager = new NativeMetricsCredentialManager(
            app(SettingRepository::class),
            $runtime,
            app(MetricsCredentialOperationLock::class),
        );
        $scope = metricsCredentialScope($node);
        $settings = metricsCredentialSettings();
        $settings->put(
            $scope,
            NativeMetricsCredentialManager::ActivePasswordKey,
            'old-active-password',
            SettingValueProtection::Secret,
        );
        $settings->put(
            $scope,
            NativeMetricsCredentialManager::PendingPasswordKey,
            'pending-password',
            SettingValueProtection::Secret,
        );

        $credentials = $manager->reset();

        expect($runtime->applied)
            ->toBe([])
            ->and($credentials->password)
            ->toBe('pending-password')
            ->and($settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey))
            ->toBe('pending-password')
            ->and($settings->get($scope, NativeMetricsCredentialManager::PendingPasswordKey))
            ->toBeNull();
    });

    it('serializes competing resets and makes the waiter read the promoted credential', function (): void {
        $generated = 0;
        Str::createRandomStringsUsing(static function (int $length) use (&$generated): string {
            $generated++;

            return str_repeat(chr(113 + $generated), $length);
        });
        $node = metricsCredentialNode();
        $scope = metricsCredentialScope($node);
        $settings = metricsCredentialSettings();
        $settings->put(
            $scope,
            NativeMetricsCredentialManager::ActivePasswordKey,
            'old-active-password',
            SettingValueProtection::Secret,
        );
        $coordinator = new MetricsCredentialLockCoordinator;
        $ownerRuntime = new MetricsCredentialRuntimeFake;
        $ownerRuntime->holds('old-active-password');
        $waiterRuntime = new MetricsCredentialRuntimeFake;
        $waiterRuntime->holds(str_repeat('r', 32));
        $owner = new NativeMetricsCredentialManager(
            $settings,
            $ownerRuntime,
            new MetricsCredentialOperationLockFake($coordinator, 'owner'),
        );
        $waiter = new NativeMetricsCredentialManager(
            $settings,
            $waiterRuntime,
            new MetricsCredentialOperationLockFake($coordinator, 'waiter'),
        );
        $refusal = null;
        $stateDuringRefusal = [];
        $coordinator->pause = function () use ($waiter, $settings, $scope, &$refusal, &$stateDuringRefusal): void {
            try {
                $waiter->reset();
            } catch (ResourceOperationException $exception) {
                $refusal = $exception;
            }

            $stateDuringRefusal = [
                $settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey),
                $settings->get($scope, NativeMetricsCredentialManager::PendingPasswordKey),
            ];
        };

        $first = $owner->reset();
        $second = $waiter->reset();

        expect($refusal)
            ->toBeInstanceOf(ResourceOperationException::class)
            ->and($refusal?->errorCode)
            ->toBe('metrics.credentials_busy')
            ->and($stateDuringRefusal)
            ->toBe(['old-active-password', null])
            ->and($first->password)
            ->toBe(str_repeat('r', 32))
            ->and($second->password)
            ->toBe(str_repeat('s', 32))
            ->and($ownerRuntime->applied)
            ->toBe([[$node->id, 'old-active-password', str_repeat('r', 32)]])
            ->and($waiterRuntime->applied)
            ->toBe([[$node->id, str_repeat('r', 32), str_repeat('s', 32)]])
            ->and($generated)
            ->toBe(2)
            ->and($coordinator->events)
            ->toBe(['owner:acquired', 'waiter:refused', 'owner:released', 'waiter:acquired', 'waiter:released']);
    });

    it('keeps reset state until a later purge acquires ownership', function (): void {
        Str::createRandomStringsUsing(static fn (int $length): string => str_repeat('r', $length));
        $node = metricsCredentialNode();
        $scope = metricsCredentialScope($node);
        $settings = metricsCredentialSettings();
        $settings->put(
            $scope,
            NativeMetricsCredentialManager::ActivePasswordKey,
            'old-active-password',
            SettingValueProtection::Secret,
        );
        $coordinator = new MetricsCredentialLockCoordinator;
        $runtime = new MetricsCredentialRuntimeFake;
        $runtime->holds('old-active-password');
        $resetter = new NativeMetricsCredentialManager(
            $settings,
            $runtime,
            new MetricsCredentialOperationLockFake($coordinator, 'reset'),
        );
        $purger = new NativeMetricsCredentialManager(
            $settings,
            new MetricsCredentialRuntimeFake,
            new MetricsCredentialOperationLockFake($coordinator, 'purge'),
        );
        $refusal = null;
        $coordinator->pause = function () use ($purger, $node, &$refusal): void {
            try {
                $purger->purge($node);
            } catch (ResourceOperationException $exception) {
                $refusal = $exception;
            }
        };

        $reset = $resetter->reset();

        expect($refusal?->errorCode)
            ->toBe('metrics.credentials_busy')
            ->and($reset->password)
            ->toBe(str_repeat('r', 32))
            ->and($settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey))
            ->toBe(str_repeat('r', 32));

        $purger->purge($node);

        expect($settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey))
            ->toBeNull()
            ->and($settings->get($scope, NativeMetricsCredentialManager::PendingPasswordKey))
            ->toBeNull();
    });

    it('keeps purge authoritative when a reset contends and permits later fresh initialization', function (): void {
        Str::createRandomStringsUsing(static fn (int $length): string => str_repeat('n', $length));
        $node = metricsCredentialNode();
        $scope = metricsCredentialScope($node);
        $settings = metricsCredentialSettings();
        $settings->put(
            $scope,
            NativeMetricsCredentialManager::ActivePasswordKey,
            'old-active-password',
            SettingValueProtection::Secret,
        );
        $settings->put(
            $scope,
            NativeMetricsCredentialManager::PendingPasswordKey,
            'old-pending-password',
            SettingValueProtection::Secret,
        );
        $coordinator = new MetricsCredentialLockCoordinator;
        $resetRuntime = new MetricsCredentialRuntimeFake;
        $purger = new NativeMetricsCredentialManager(
            $settings,
            new MetricsCredentialRuntimeFake,
            new MetricsCredentialOperationLockFake($coordinator, 'purge'),
        );
        $resetter = new NativeMetricsCredentialManager(
            $settings,
            $resetRuntime,
            new MetricsCredentialOperationLockFake($coordinator, 'reset'),
        );
        $refusal = null;
        $coordinator->pause = function () use ($resetter, &$refusal): void {
            try {
                $resetter->reset();
            } catch (ResourceOperationException $exception) {
                $refusal = $exception;
            }
        };

        $purger->purge($node);

        expect($refusal?->errorCode)
            ->toBe('metrics.credentials_busy')
            ->and($resetRuntime->applied)
            ->toBe([])
            ->and($settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey))
            ->toBeNull()
            ->and($settings->get($scope, NativeMetricsCredentialManager::PendingPasswordKey))
            ->toBeNull();

        try {
            $resetter->reset();
            $postPurgeFailure = null;
        } catch (ResourceOperationException $exception) {
            $postPurgeFailure = $exception;
        }

        expect($postPurgeFailure)
            ->toBeInstanceOf(ResourceOperationException::class)
            ->and($postPurgeFailure?->errorCode)
            ->toBe('metrics.credentials_missing')
            ->and($postPurgeFailure?->status)
            ->toBe(422)
            ->and($settings->get($scope, NativeMetricsCredentialManager::PendingPasswordKey))
            ->toBeNull()
            ->and(Setting::query()
                ->where('scope_type', SettingScopeType::Node->value)
                ->where('scope_id', $node->id)
                ->where('key', NativeMetricsCredentialManager::PendingPasswordKey)
                ->exists())
            ->toBeFalse()
            ->and($resetter->passwordForConvergence($node))
            ->toBe(str_repeat('n', 32));
    });

    it('creates one initial password when another manager contends and then reuses authoritative state', function (): void {
        $generated = 0;
        Str::createRandomStringsUsing(static function (int $length) use (&$generated): string {
            $generated++;

            return str_repeat('i', $length);
        });
        $node = metricsCredentialNode();
        $settings = metricsCredentialSettings();
        $coordinator = new MetricsCredentialLockCoordinator;
        $owner = new NativeMetricsCredentialManager(
            $settings,
            new MetricsCredentialRuntimeFake,
            new MetricsCredentialOperationLockFake($coordinator, 'owner'),
        );
        $waiter = new NativeMetricsCredentialManager(
            $settings,
            new MetricsCredentialRuntimeFake,
            new MetricsCredentialOperationLockFake($coordinator, 'waiter'),
        );
        $refusal = null;
        $coordinator->pause = function () use ($waiter, $node, &$refusal): void {
            try {
                $waiter->passwordForConvergence($node);
            } catch (ResourceOperationException $exception) {
                $refusal = $exception;
            }
        };

        $created = $owner->passwordForConvergence($node);
        $reused = $waiter->passwordForConvergence($node);

        expect($refusal?->errorCode)
            ->toBe('metrics.credentials_busy')
            ->and($created)
            ->toBe(str_repeat('i', 32))
            ->and($reused)
            ->toBe($created)
            ->and($generated)
            ->toBe(1);
    });

    it('serializes a verified read with reset and rereads the promoted credential afterward', function (): void {
        Str::createRandomStringsUsing(static fn (int $length): string => str_repeat('v', $length));
        $node = metricsCredentialNode();
        $scope = metricsCredentialScope($node);
        $settings = metricsCredentialSettings();
        $settings->put(
            $scope,
            NativeMetricsCredentialManager::ActivePasswordKey,
            'old-active-password',
            SettingValueProtection::Secret,
        );
        $coordinator = new MetricsCredentialLockCoordinator;
        $readRuntime = new MetricsCredentialRuntimeFake;
        $readRuntime->holds('old-active-password');
        $resetRuntime = new MetricsCredentialRuntimeFake;
        $resetRuntime->holds('old-active-password');
        $reader = new NativeMetricsCredentialManager(
            $settings,
            $readRuntime,
            new MetricsCredentialOperationLockFake($coordinator, 'read'),
        );
        $resetter = new NativeMetricsCredentialManager(
            $settings,
            $resetRuntime,
            new MetricsCredentialOperationLockFake($coordinator, 'reset'),
        );
        $refusal = null;
        $coordinator->pause = function () use ($resetter, &$refusal): void {
            try {
                $resetter->reset();
            } catch (ResourceOperationException $exception) {
                $refusal = $exception;
            }
        };

        $before = $reader->credentials();
        $afterReset = $resetter->reset();
        $readRuntime->holds($afterReset->password);
        $after = $reader->credentials();

        expect($refusal?->errorCode)
            ->toBe('metrics.credentials_busy')
            ->and($before->password)
            ->toBe('old-active-password')
            ->and($after->password)
            ->toBe(str_repeat('v', 32));
    });

    it('retains encrypted pending state when apply fails and retries under a released owner', function (): void {
        Str::createRandomStringsUsing(static fn (int $length): string => str_repeat('f', $length));
        $node = metricsCredentialNode();
        $scope = metricsCredentialScope($node);
        $settings = metricsCredentialSettings();
        $settings->put(
            $scope,
            NativeMetricsCredentialManager::ActivePasswordKey,
            'old-active-password',
            SettingValueProtection::Secret,
        );
        $coordinator = new MetricsCredentialLockCoordinator;
        $runtime = new MetricsCredentialRuntimeFake;
        $runtime->holds('old-active-password');
        $runtime->applyFailure = new RuntimeException('Grafana apply failed.');
        $manager = new NativeMetricsCredentialManager(
            $settings,
            $runtime,
            new MetricsCredentialOperationLockFake($coordinator, 'reset'),
        );
        $failure = null;

        try {
            $manager->reset();
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }

        $storedPending = Setting::query()
            ->where('scope_type', SettingScopeType::Node->value)
            ->where('scope_id', $node->id)
            ->where('key', NativeMetricsCredentialManager::PendingPasswordKey)
            ->sole();
        $pending = str_repeat('f', 32);
        expect($failure?->getMessage())
            ->toBe('Grafana apply failed.')
            ->not->toContain($pending)
            ->and((string) $failure)
            ->not->toContain($pending)
            ->and($storedPending->is_secret)
            ->toBeTrue()
            ->and($storedPending->value)
            ->not->toBe($pending)
            ->and($settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey))
            ->toBe('old-active-password')
            ->and($coordinator->owner)
            ->toBeNull();

        $runtime->applyFailure = null;
        $credentials = $manager->reset();

        expect($credentials->password)
            ->toBe($pending)
            ->and($runtime->applied)
            ->toBe([[$node->id, 'old-active-password', $pending]]);
    });

    it('retains applied pending state after an authentication exception and promotes it without reapply', function (): void {
        Str::createRandomStringsUsing(static fn (int $length): string => str_repeat('e', $length));
        $node = metricsCredentialNode();
        $scope = metricsCredentialScope($node);
        $settings = metricsCredentialSettings();
        $settings->put(
            $scope,
            NativeMetricsCredentialManager::ActivePasswordKey,
            'old-active-password',
            SettingValueProtection::Secret,
        );
        $runtime = new MetricsCredentialRuntimeFake;
        $runtime->holds('old-active-password');
        $runtime->verifyFailures[] = new RuntimeException('Grafana authentication failed safely.');
        $manager = new NativeMetricsCredentialManager(
            $settings,
            $runtime,
            app(MetricsCredentialOperationLock::class),
        );

        expect(fn () => $manager->reset())
            ->toThrow(RuntimeException::class, 'Grafana authentication failed safely.');

        $pending = str_repeat('e', 32);
        expect($settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey))
            ->toBe('old-active-password')
            ->and($settings->get($scope, NativeMetricsCredentialManager::PendingPasswordKey))
            ->toBe($pending)
            ->and($runtime->held())
            ->toBe($pending)
            ->and($runtime->applied)
            ->toBe([[$node->id, 'old-active-password', $pending]]);

        $credentials = $manager->reset();

        expect($credentials->password)
            ->toBe($pending)
            ->and($runtime->applied)
            ->toHaveCount(1)
            ->and($settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey))
            ->toBe($pending)
            ->and($settings->get($scope, NativeMetricsCredentialManager::PendingPasswordKey))
            ->toBeNull();
    });

    it('acquires ownership and performs remote work outside database transactions', function (): void {
        Str::createRandomStringsUsing(static fn (int $length): string => str_repeat('t', $length));
        $node = metricsCredentialNode();
        $settings = metricsCredentialSettings();
        $settings->put(
            metricsCredentialScope($node),
            NativeMetricsCredentialManager::ActivePasswordKey,
            'old-active-password',
            SettingValueProtection::Secret,
        );
        $coordinator = new MetricsCredentialLockCoordinator;
        $runtime = new MetricsCredentialRuntimeFake;
        $runtime->holds('old-active-password');
        $ambientTransactionLevel = DB::transactionLevel();
        $manager = new NativeMetricsCredentialManager(
            $settings,
            $runtime,
            new MetricsCredentialOperationLockFake($coordinator, 'reset'),
        );

        $manager->reset();

        expect($coordinator->transactionLevels)
            ->toBe([$ambientTransactionLevel])
            ->and($runtime->transactionLevels)
            ->toBe([$ambientTransactionLevel, $ambientTransactionLevel]);
    });

    it('does not mutate credential state when native ownership acquisition is refused', function (): void {
        $node = metricsCredentialNode();
        $directory = sys_get_temp_dir().'/orbit-metrics-credential-state-'.Str::uuid();
        mkdir($directory, permissions: 0o700, recursive: true);
        $held = fopen($directory."/node-{$node->id}.lock", mode: 'c+');
        expect($held)->not->toBeFalse();
        flock($held, LOCK_EX);
        $now = 0.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $manager = new NativeMetricsCredentialManager(
            metricsCredentialSettings(),
            new MetricsCredentialRuntimeFake,
            new NativeMetricsCredentialOperationLock(
                $directory,
                new CommandDeadline($clock),
                $clock,
                static function (int $microseconds) use (&$now): void {
                    $now += $microseconds / 1_000_000;
                },
            ),
        );

        try {
            expect(fn () => $manager->passwordForConvergence($node))
                ->toThrow(function (ResourceOperationException $exception): void {
                    expect($exception->errorCode)
                        ->toBe('metrics.credentials_busy')
                        ->and($exception->status)
                        ->toBe(409);
                });

            expect(metricsCredentialSettings()->get(
                metricsCredentialScope($node),
                NativeMetricsCredentialManager::ActivePasswordKey,
            ))
                ->toBeNull()
                ->and(round($now, 1))
                ->toBe(30.0);
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
            new Filesystem()->deleteDirectory($directory);
        }
    });

    it('purges only the active and pending credential settings', function (): void {
        $node = metricsCredentialNode();
        $manager = new NativeMetricsCredentialManager(
            app(SettingRepository::class),
            new MetricsCredentialRuntimeFake,
            app(MetricsCredentialOperationLock::class),
        );
        $scope = metricsCredentialScope($node);
        $settings = metricsCredentialSettings();
        $settings->put(
            $scope,
            NativeMetricsCredentialManager::ActivePasswordKey,
            'active',
            SettingValueProtection::Secret,
        );
        $settings->put(
            $scope,
            NativeMetricsCredentialManager::PendingPasswordKey,
            'pending',
            SettingValueProtection::Secret,
        );
        $settings->put($scope, 'metrics.exporter.preference', 'enabled');

        $manager->purge($node);

        expect($settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey))
            ->toBeNull()
            ->and($settings->get($scope, NativeMetricsCredentialManager::PendingPasswordKey))
            ->toBeNull()
            ->and($settings->get($scope, 'metrics.exporter.preference'))
            ->toBe('enabled');
    });

    it('fails closed when the assignment or verified credential is unavailable', function (): void {
        $runtime = new MetricsCredentialRuntimeFake;
        $manager = new NativeMetricsCredentialManager(
            app(SettingRepository::class),
            $runtime,
            app(MetricsCredentialOperationLock::class),
        );

        expect(fn () => $manager->credentials())
            ->toThrow(ResourceOperationException::class, 'Metrics is not assigned.');

        $node = metricsCredentialNode();
        expect(fn () => $manager->credentials())
            ->toThrow(ResourceOperationException::class, 'Grafana credentials are unavailable.');

        metricsCredentialSettings()->put(
            metricsCredentialScope($node),
            NativeMetricsCredentialManager::ActivePasswordKey,
            'unverified-password',
            SettingValueProtection::Secret,
        );
        $runtime->accepts = false;

        expect(fn () => $manager->credentials())
            ->toThrow(ResourceOperationException::class, 'Grafana rejected the active credential.');
    });
});

function metricsCredentialNode(): Node
{
    $node = Node::query()->create([
        'name' => 'metrics-credentials',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.73',
        'wireguard_ip' => '10.44.0.3',
    ]);
    $node->roles()->create([
        'role' => RoleName::Metrics,
        'status' => LifecycleStatus::Active,
    ]);

    return $node;
}

function metricsCredentialSettings(): SettingRepository
{
    return app(SettingRepository::class);
}

function metricsCredentialScope(Node $node): SettingScope
{
    return new SettingScope(SettingScopeType::Node, $node->id);
}

final class MetricsCredentialRuntimeFake implements MetricsCredentialRuntime
{
    public bool $accepts = true;

    public ?RuntimeException $applyFailure = null;

    /** @var list<RuntimeException> */
    public array $verifyFailures = [];

    /**
     * What Grafana actually answers to. When the fake holds a credential it
     * accepts only that one, and `apply()` is the only thing that changes it,
     * which is how a partial reset leaves a pending credential unusable.
     */
    private ?string $held = null;

    /** @var list<array{int, string, string}> */
    public array $applied = [];

    /** @var list<array{int, string}> */
    public array $verified = [];

    /** @var list<int> */
    public array $transactionLevels = [];

    public function apply(
        Node $node,
        #[SensitiveParameter]
        string $activePassword,
        #[SensitiveParameter]
        string $pendingPassword,
    ): void {
        $this->transactionLevels[] = DB::transactionLevel();

        if ($this->applyFailure instanceof RuntimeException) {
            throw $this->applyFailure;
        }

        $this->applied[] = [$node->id, $activePassword, $pendingPassword];

        if ($this->held !== null) {
            $this->held = $pendingPassword;
        }
    }

    public function holds(#[SensitiveParameter] string $credential): void
    {
        $this->held = $credential;
    }

    public function held(): ?string
    {
        return $this->held;
    }

    public function verify(Node $node, #[SensitiveParameter] string $password): bool
    {
        $this->transactionLevels[] = DB::transactionLevel();
        $this->verified[] = [$node->id, $password];

        $failure = array_shift($this->verifyFailures);

        if ($failure instanceof RuntimeException) {
            throw $failure;
        }

        if ($this->held !== null) {
            return hash_equals($this->held, $password);
        }

        return $this->accepts;
    }
}

final class MetricsCredentialLockCoordinator
{
    public ?int $owner = null;

    public ?Closure $pause = null;

    /** @var list<string> */
    public array $events = [];

    /** @var list<int> */
    public array $transactionLevels = [];
}

final readonly class MetricsCredentialOperationLockFake implements MetricsCredentialOperationLock
{
    public function __construct(
        private MetricsCredentialLockCoordinator $coordinator,
        private string $name,
    ) {}

    public function run(int $nodeId, Closure $operation): mixed
    {
        if ($this->coordinator->owner !== null) {
            $this->coordinator->events[] = "{$this->name}:refused";

            throw new ResourceOperationException(
                'metrics.credentials_busy',
                'Another Metrics credential operation is active for this Node. Retry the request.',
                409,
            );
        }

        $this->coordinator->owner = $nodeId;
        $this->coordinator->events[] = "{$this->name}:acquired";
        $this->coordinator->transactionLevels[] = DB::transactionLevel();
        $pause = $this->coordinator->pause;
        $this->coordinator->pause = null;

        try {
            $pause?->__invoke();

            return $operation();
        } finally {
            $this->coordinator->owner = null;
            $this->coordinator->events[] = "{$this->name}:released";
        }
    }
}
