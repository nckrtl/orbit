<?php

declare(strict_types=1);

use App\Actions\Routes\CreateRouteAction;
use App\Data\AppInstances\CreateAppInstanceData;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\DevelopmentSourceProfile;
use App\Domain\AppInstances\DevelopmentSourceResolution;
use App\Domain\AppInstances\ProductionAppInstanceSourceLifecycle;
use App\Domain\AppInstances\ProductionRouteProjector;
use App\Domain\Instances\CertificateMode;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteStateResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppInstances\NativeProductionAppInstanceProvisioner;
use App\Infrastructure\AppProd\AppProdSiteRepository;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Route;

beforeEach(function (): void {
    $this->orbitApp = OrbitApp::query()->create([
        'name' => 'Production app',
        'slug' => 'production-app',
        'repository_url' => 'https://example.test/production.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $this->node = Node::query()->create([
        'name' => 'production',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'tld' => 'production.test',
        'public_ssh_host' => '192.0.2.40',
        'wireguard_ip' => '10.44.0.40',
        'user' => 'orbit',
    ]);
    $this->node->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $this->source = new class implements ProductionAppInstanceSourceLifecycle {
        /** @var list<string> */
        public array $calls = [];

        public ?string $fail = null;

        public function prepareUser(AppInstance $appInstance): void
        {
            $this->record('user');
        }

        public function prepareSource(AppInstance $appInstance, bool $allowExisting): void
        {
            $this->record('source');
        }

        public function resolve(AppInstance $appInstance): DevelopmentSourceResolution
        {
            $this->record('resolve');

            return new DevelopmentSourceResolution('main', str_repeat('c', 40));
        }

        public function inspectProfile(AppInstance $appInstance): DevelopmentSourceProfile
        {
            $this->record('profile');

            return new DevelopmentSourceProfile('8.5', false);
        }

        public function prepareCaddyAccess(AppInstance $appInstance): void
        {
            $this->record('access');
        }

        private function record(string $operation): void
        {
            $this->calls[] = $operation;

            if ($this->fail === $operation) {
                throw new RuntimeConvergenceException(
                    step: $operation,
                    errorCode: "app-prod.{$operation}_failed",
                    message: 'Injected production source failure.',
                );
            }
        }
    };
    $this->projection = new class implements ProductionRouteProjector {
        /** @var list<string> */
        public array $calls = [];

        public ?string $fail = null;

        public function prepareRuntime(AppInstance $appInstance, Route $route): void
        {
            $this->record('runtime');
        }

        public function prepareCertificate(AppInstance $appInstance, Route $route): void
        {
            $this->record('certificate');
        }

        public function prepareFirewall(AppInstance $appInstance): void
        {
            $this->record('firewall');
        }

        public function publish(AppInstance $appInstance, Route $route): void
        {
            $this->record('route');
        }

        private function record(string $operation): void
        {
            $this->calls[] = $operation;

            if ($this->fail === $operation) {
                throw new RuntimeConvergenceException(
                    step: $operation,
                    errorCode: "app-prod.{$operation}_failed",
                    message: 'Injected production projection failure.',
                );
            }
        }
    };
    $lock = new class implements AppDevSourceOperationLock {
        public function synchronized(int $nodeId, \Closure $operation): mixed
        {
            return $operation();
        }
    };
    $this->provisioner = new NativeProductionAppInstanceProvisioner(
        $lock,
        $this->source,
        app(CreateRouteAction::class),
        app(RouteStateResolver::class),
        new AppProdSiteRepository,
        $this->projection,
    );
    $this->data = new CreateAppInstanceData(
        appId: $this->orbitApp->id,
        nodeId: $this->node->id,
        name: 'live',
        root: null,
        hostname: null,
        branch: null,
    );
});

it('refuses a live legacy public production footprint before reserving or mutating', function (
    LifecycleStatus $status,
): void {
    $legacy = orb197_legacy_production_instance($this->node, $status);

    expect(fn () => $this->provisioner->execute($this->data, $this->orbitApp, $this->node, null))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)
                ->toBe('instance.legacy_production_conflict')
                ->and($exception->status)
                ->toBe(409)
                ->and($exception->getMessage())
                ->toBe('The selected Node still serves a legacy public production Instance.');
        });

    expect(AppInstance::query()->exists())
        ->toBeFalse()
        ->and(Route::query()->exists())
        ->toBeFalse()
        ->and($this->source->calls)
        ->toBeEmpty()
        ->and($this->projection->calls)
        ->toBeEmpty()
        ->and($legacy->refresh()->status)
        ->toBe($status);
})->with([
    'provisioning legacy Instance' => LifecycleStatus::Provisioning,
    'active legacy Instance' => LifecycleStatus::Active,
]);

it('allows creation after the selected Node has no live legacy public footprint', function (): void {
    $inactive = orb197_legacy_production_instance($this->node, LifecycleStatus::Failed);
    $otherNode = Node::query()->create([
        'name' => 'other-production',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'tld' => 'other.test',
        'public_ssh_host' => '192.0.2.41',
        'wireguard_ip' => '10.44.0.41',
        'user' => 'orbit',
    ]);
    orb197_legacy_production_instance($otherNode, LifecycleStatus::Active);
    $unrelated = orb197_legacy_production_instance($this->node, LifecycleStatus::Active);
    $unrelated->update(['certificate_mode' => CertificateMode::OrbitCa]);

    $result = $this->provisioner->execute($this->data, $this->orbitApp, $this->node, null);

    expect($result['appInstance']->status)
        ->toBe(AppInstanceState::Active)
        ->and($result['created'])
        ->toBeTrue()
        ->and(AppInstance::query()->count())
        ->toBe(1)
        ->and(Route::query()->count())
        ->toBe(1)
        ->and($inactive->refresh()->status)
        ->toBe(LifecycleStatus::Failed)
        ->and($this->source->calls)
        ->not->toBeEmpty()->and($this->projection->calls)
        ->not->toBeEmpty();
});

it('resumes each failed production boundary from its durable checkpoint without duplicate records', function (
    string $failure,
    ?string $checkpoint,
    bool $routeExists,
): void {
    if (in_array($failure, ['user', 'source', 'resolve', 'profile', 'access'], strict: true)) {
        $this->source->fail = $failure;
    } else {
        $this->projection->fail = $failure;
    }

    expect(fn () => $this->provisioner->execute($this->data, $this->orbitApp, $this->node, null))
        ->toThrow(RuntimeConvergenceException::class);

    expect(AppInstance::query()->sole()->provisioning_step)
        ->toBe($checkpoint)
        ->and(Route::query()->exists())
        ->toBe($routeExists);

    $this->source->fail = null;
    $this->projection->fail = null;
    $result = $this->provisioner->execute($this->data, $this->orbitApp, $this->node, null);

    expect($result['created'])
        ->toBeFalse()
        ->and($result['appInstance']->status)
        ->toBe(AppInstanceState::Active)
        ->and(AppInstance::query()->count())
        ->toBe(1)
        ->and(Route::query()->count())
        ->toBe(1);
})->with([
    'user' => ['user', null, false],
    'source' => ['source', 'user-prepared', false],
    'branch' => ['resolve', 'source-prepared', false],
    'classification' => ['profile', 'source-resolved', false],
    'Caddy source access' => ['access', 'source-classified', false],
    'runtime' => ['runtime', 'source-classified', true],
    'certificate' => ['certificate', 'runtime-prepared', true],
    'firewall' => ['firewall', 'certificate-prepared', true],
    'Route publication' => ['route', 'firewall-prepared', true],
]);

it('uses the actual signed 64-bit App ID in a valid persisted Linux identity', function (): void {
    $this->orbitApp->delete();
    $app = new OrbitApp([
        'name' => 'Maximum ID',
        'slug' => 'maximum-id',
        'repository_url' => 'https://example.test/maximum.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $app->id = PHP_INT_MAX;
    $app->save();
    $data = new CreateAppInstanceData($app->id, $this->node->id, 'maximum', null, null, null);

    $result = $this->provisioner->execute($data, $app, $this->node, null);

    expect($result['appInstance']->production_user)
        ->toBe('orbit-app-9223372036854775807')
        ->and(strlen((string) $result['appInstance']->production_user))
        ->toBeLessThanOrEqual(32)
        ->and($result['appInstance']->production_home)
        ->toBe('/home/orbit-app-9223372036854775807');
});

function orb197_legacy_production_instance(Node $node, LifecycleStatus $status): Instance
{
    $slug = 'legacy-production-'.OrbitApp::query()->count();
    $app = OrbitApp::query()->create([
        'name' => 'Legacy production',
        'slug' => $slug,
        'repository_url' => "https://example.test/{$slug}.git",
        'default_branch' => 'main',
        'root' => 'public',
    ]);

    return Instance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => "/var/www/{$app->slug}",
        'document_root' => 'public',
        'php_version' => '8.5',
        'hostname' => "{$app->slug}.example.test",
        'certificate_mode' => CertificateMode::Acme,
        'status' => $status,
    ]);
}
