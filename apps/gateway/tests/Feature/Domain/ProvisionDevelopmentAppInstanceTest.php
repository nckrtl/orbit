<?php

declare(strict_types=1);

use App\Actions\Routes\CreateRouteAction;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\DevelopmentAppInstanceConfigurator;
use App\Domain\AppInstances\DevelopmentRouteProjector;
use App\Domain\AppInstances\DevelopmentSourceProfile;
use App\Domain\Routes\RouteStatus;
use App\Infrastructure\AppInstances\NativeDevelopmentAppInstanceProvisioner;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Route;

beforeEach(function (): void {
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://example.test/acme.git',
        'main_branch' => 'main',
        'root' => 'public',
    ]);
    $node = Node::query()->create([
        'name' => 'development',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.10',
        'wireguard_ip' => '10.44.0.10',
        'tld' => 'test',
    ]);
    $this->instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'feature',
        'checkout_path' => '/srv/acme/feature',
        'branch' => 'feature',
        'starting_commit' => str_repeat('a', 40),
        'status' => AppInstanceState::SourceResolved,
    ]);
    $this->configuration = new class implements DevelopmentAppInstanceConfigurator {
        public int $inspections = 0;

        public int $configurations = 0;

        public bool $failInspection = false;

        public bool $failConfiguration = false;

        public function inspect(AppInstance $appInstance): DevelopmentSourceProfile
        {
            $this->inspections++;
            if ($this->failInspection) {
                throw provisioning_failure('source-classification');
            }

            return new DevelopmentSourceProfile('8.5', true);
        }

        public function configureLaravelUrl(AppInstance $appInstance, string $url): void
        {
            $this->configurations++;
            if ($this->failConfiguration) {
                throw provisioning_failure('laravel-url');
            }
        }
    };
    $this->projection = new class implements DevelopmentRouteProjector {
        public int $convergences = 0;

        public bool $fail = false;

        public function converge(AppInstance $appInstance, Route $route): void
        {
            $this->convergences++;
            if ($this->fail) {
                throw provisioning_failure('runtime');
            }
        }
    };
    $this->provisioner = new NativeDevelopmentAppInstanceProvisioner(
        app(CreateRouteAction::class),
        $this->configuration,
        $this->projection,
    );
});

it('retains source classification failure evidence and reuses the sole Route', function (): void {
    $this->provisioner->reserve($this->instance, null);
    $routeId = $this->instance->routes()->sole()->id;
    $this->configuration->failInspection = true;

    expect(fn () => $this->provisioner->complete($this->instance, null))
        ->toThrow(RuntimeConvergenceException::class);
    expect($this->instance->refresh()->provisioning_step)
        ->toBeNull()
        ->and($this->instance->routes()->sole()->status)
        ->toBe(RouteStatus::Failed);

    $this->configuration->failInspection = false;
    $this->provisioner->reserve($this->instance, null);
    $result = $this->provisioner->complete($this->instance, null);

    expect($result->status)
        ->toBe(AppInstanceState::Active)
        ->and($result->routes()->sole()->id)
        ->toBe($routeId)
        ->and($result->routes()->count())
        ->toBe(1);
});

it('resumes Laravel URL configuration from selected PHP evidence', function (): void {
    $this->provisioner->reserve($this->instance, null);
    $this->configuration->failConfiguration = true;

    expect(fn () => $this->provisioner->complete($this->instance, null))
        ->toThrow(RuntimeConvergenceException::class);
    expect($this->instance->refresh()->provisioning_step)->toBe('php-selected');

    $this->configuration->failConfiguration = false;
    $this->provisioner->reserve($this->instance, null);
    $this->provisioner->complete($this->instance, null);

    expect($this->configuration->configurations)
        ->toBe(2)
        ->and($this->projection->convergences)
        ->toBe(1)
        ->and($this->instance->routes()->count())
        ->toBe(1);
});

it('does not repeat Laravel configuration after a projection failure', function (): void {
    $this->provisioner->reserve($this->instance, null);
    $this->projection->fail = true;

    expect(fn () => $this->provisioner->complete($this->instance, null))
        ->toThrow(RuntimeConvergenceException::class);
    expect($this->instance->refresh()->provisioning_step)->toBe('url-configured');

    $this->projection->fail = false;
    $this->provisioner->reserve($this->instance, null);
    $this->provisioner->complete($this->instance, null);

    expect($this->configuration->configurations)
        ->toBe(1)
        ->and($this->projection->convergences)
        ->toBe(2)
        ->and($this->instance->routes()->count())
        ->toBe(1);
});

function provisioning_failure(string $step): RuntimeConvergenceException
{
    return new RuntimeConvergenceException(
        step: $step,
        errorCode: "app-dev.{$step}_failed",
        message: "The {$step} boundary failed.",
    );
}
