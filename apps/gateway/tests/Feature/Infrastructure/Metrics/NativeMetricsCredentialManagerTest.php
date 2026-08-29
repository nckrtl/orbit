<?php

declare(strict_types=1);

use App\Domain\Metrics\MetricsCredentialRuntime;
use App\Domain\Nodes\RoleName;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\NativeMetricsCredentialManager;
use App\Models\Node;
use App\Models\Setting;
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
        $manager = new NativeMetricsCredentialManager(app(SettingRepository::class), $runtime);
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
        $manager = new NativeMetricsCredentialManager(app(SettingRepository::class), $runtime);
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

    it('purges only the active and pending credential settings', function (): void {
        $node = metricsCredentialNode();
        $manager = new NativeMetricsCredentialManager(
            app(SettingRepository::class),
            new MetricsCredentialRuntimeFake,
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
        $manager = new NativeMetricsCredentialManager(app(SettingRepository::class), $runtime);

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
        'wireguard_address' => '10.44.0.3',
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

    /** @var list<array{int, string, string}> */
    public array $applied = [];

    /** @var list<array{int, string}> */
    public array $verified = [];

    public function apply(
        Node $node,
        #[SensitiveParameter]
        string $activePassword,
        #[SensitiveParameter]
        string $pendingPassword,
    ): void {
        $this->applied[] = [$node->id, $activePassword, $pendingPassword];
    }

    public function verify(Node $node, #[SensitiveParameter] string $password): bool
    {
        $this->verified[] = [$node->id, $password];

        return $this->accepts;
    }
}
