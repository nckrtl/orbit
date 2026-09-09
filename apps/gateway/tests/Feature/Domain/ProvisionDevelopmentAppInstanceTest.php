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
        'default_branch' => 'main',
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

        public bool $laravel = true;

        public ?string $phpVersion = '8.5';

        public function inspect(AppInstance $appInstance): DevelopmentSourceProfile
        {
            $this->inspections++;
            if ($this->failInspection) {
                throw provisioning_failure('source-classification');
            }

            return new DevelopmentSourceProfile($this->phpVersion, $this->laravel);
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

        public ?RuntimeConvergenceException $failure = null;

        public function converge(AppInstance $appInstance, Route $route): void
        {
            $this->convergences++;
            if ($this->failure instanceof RuntimeConvergenceException) {
                throw $this->failure;
            }
        }
    };
    $this->provisioner = new NativeDevelopmentAppInstanceProvisioner(
        app(CreateRouteAction::class),
        $this->configuration,
        $this->projection,
    );
});

it('reports source classification failure without persisting it and reuses the sole Route', function (): void {
    $this->provisioner->reserve($this->instance, null);
    $routeId = $this->instance->routes()->sole()->id;
    $this->configuration->failInspection = true;

    expect(fn () => $this->provisioner->complete($this->instance, null))
        ->toThrow(RuntimeConvergenceException::class);
    expect($this->instance->refresh()->provisioning_step)
        ->toBeNull()
        ->and($this->instance->failed_step)
        ->toBeNull()
        ->and($this->instance->error_code)
        ->toBeNull()
        ->and($this->instance->routes()->sole()->only(['status', 'failed_step', 'error_code']))
        ->toBe([
            'status' => RouteStatus::Pending,
            'failed_step' => null,
            'error_code' => null,
        ]);

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
    expect(
        $this->instance
            ->refresh()
            ->only([
                'selected_php_version',
                'source_is_laravel',
                'provisioning_step',
            ]),
    )->toBe([
        'selected_php_version' => '8.5',
        'source_is_laravel' => true,
        'provisioning_step' => 'php-selected',
    ]);

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

it('rejects every complete source profile change at each retained checkpoint', function (
    string $checkpoint,
    ?string $recordedPhp,
    bool $recordedLaravel,
    ?string $currentPhp,
    bool $currentLaravel,
): void {
    $this->instance->update([
        'selected_php_version' => $recordedPhp,
        'source_is_laravel' => $recordedLaravel,
        'provisioning_step' => $checkpoint,
    ]);
    $this->configuration->phpVersion = $currentPhp;
    $this->configuration->laravel = $currentLaravel;
    $this->provisioner->reserve($this->instance, null);

    try {
        $this->provisioner->complete($this->instance, null);
        $this->fail('Expected source profile drift refusal.');
    } catch (RuntimeConvergenceException $exception) {
        expect($exception->errorCode)->toBe('app-dev.source_evidence_changed');
    }

    expect(
        $this->instance
            ->refresh()
            ->only([
                'selected_php_version',
                'source_is_laravel',
                'provisioning_step',
            ]),
    )
        ->toBe([
            'selected_php_version' => $recordedPhp,
            'source_is_laravel' => $recordedLaravel,
            'provisioning_step' => $checkpoint,
        ])
        ->and($this->configuration->configurations)
        ->toBe(0)
        ->and($this->projection->convergences)
        ->toBe(0);
})->with([
    'Laravel to plain PHP at php-selected' => ['php-selected', '8.5', true, '8.5', false],
    'Laravel to plain PHP at url-configured' => ['url-configured', '8.5', true, '8.5', false],
    'plain PHP to Laravel at php-selected' => ['php-selected', '8.5', false, '8.5', true],
    'plain PHP to Laravel at url-configured' => ['url-configured', '8.5', false, '8.5', true],
    'PHP to non-PHP at php-selected' => ['php-selected', '8.5', false, null, false],
    'PHP to non-PHP at url-configured' => ['url-configured', '8.5', false, null, false],
]);

it('fails closed for legacy incomplete profile evidence without inspecting or mutating projections', function (
    string $checkpoint,
): void {
    $this->instance->update([
        'selected_php_version' => '8.5',
        'source_is_laravel' => null,
        'provisioning_step' => $checkpoint,
    ]);
    $this->provisioner->reserve($this->instance, null);

    try {
        $this->provisioner->complete($this->instance, null);
        $this->fail('Expected incomplete source profile refusal.');
    } catch (RuntimeConvergenceException $exception) {
        expect($exception->errorCode)->toBe('app-dev.source_evidence_changed');
    }

    expect($this->configuration->inspections)
        ->toBe(0)
        ->and($this->configuration->configurations)
        ->toBe(0)
        ->and($this->projection->convergences)
        ->toBe(0)
        ->and($this->instance->refresh()->provisioning_step)
        ->toBe($checkpoint);
})->with(['php-selected', 'url-configured']);

it('recovers only incomplete legacy evidence and preserves identity', function (): void {
    $this->instance->update([
        'selected_php_version' => '8.4',
        'source_is_laravel' => null,
        'provisioning_step' => 'url-configured',
    ]);
    $this->configuration->phpVersion = '8.5';
    $this->configuration->laravel = false;
    $this->provisioner->reserve($this->instance, null);
    $routeId = $this->instance->routes()->sole()->id;
    $identity = $this->instance->only([
        'id',
        'app_id',
        'node_id',
        'checkout_path',
        'branch',
        'starting_commit',
    ]);

    $result = $this->provisioner->complete($this->instance, null, true);

    expect($result->only([
        'selected_php_version',
        'source_is_laravel',
        'status',
        'provisioning_step',
    ]))
        ->toBe([
            'selected_php_version' => '8.5',
            'source_is_laravel' => false,
            'status' => AppInstanceState::Active,
            'provisioning_step' => 'active',
        ])
        ->and($result->only(array_keys($identity)))
        ->toBe($identity)
        ->and($result->routes()->sole()->id)
        ->toBe($routeId);
});

it('retries recovered Laravel URL reconciliation from its durable complete checkpoint', function (): void {
    $this->instance->update([
        'selected_php_version' => '8.5',
        'source_is_laravel' => null,
        'provisioning_step' => 'url-configured',
    ]);
    $this->configuration->failConfiguration = true;
    $this->provisioner->reserve($this->instance, null);

    expect(fn () => $this->provisioner->complete($this->instance, null, true))
        ->toThrow(RuntimeConvergenceException::class);
    expect(
        $this->instance
            ->refresh()
            ->only([
                'selected_php_version',
                'source_is_laravel',
                'provisioning_step',
            ]),
    )->toBe([
        'selected_php_version' => '8.5',
        'source_is_laravel' => true,
        'provisioning_step' => 'php-selected',
    ]);

    $this->configuration->failConfiguration = false;
    $result = $this->provisioner->complete($this->instance, null);

    expect($result->status)
        ->toBe(AppInstanceState::Active)
        ->and($this->configuration->configurations)
        ->toBe(2)
        ->and($this->projection->convergences)
        ->toBe(1);
});

it('does not let recovery bypass complete profile drift', function (): void {
    $this->instance->update([
        'selected_php_version' => '8.5',
        'source_is_laravel' => false,
        'provisioning_step' => 'php-selected',
    ]);
    $this->configuration->laravel = true;
    $this->provisioner->reserve($this->instance, null);

    try {
        $this->provisioner->complete($this->instance, null, true);
        $this->fail('Expected complete source profile drift refusal.');
    } catch (RuntimeConvergenceException $exception) {
        expect($exception->errorCode)->toBe('app-dev.source_evidence_changed');
    }
});

it('keeps an Active AppInstance terminal without profile inspection during recovery', function (): void {
    $this->provisioner->reserve($this->instance, null);
    $active = $this->provisioner->complete($this->instance, null);
    $this->configuration->inspections = 0;
    $this->configuration->failInspection = true;

    $result = $this->provisioner->complete($active, null, true);

    expect($result->status)
        ->toBe(AppInstanceState::Active)
        ->and($this->configuration->inspections)
        ->toBe(0)
        ->and($this->projection->convergences)
        ->toBe(1);
});

it('does not repeat Laravel configuration after a projection failure', function (): void {
    $this->provisioner->reserve($this->instance, null);
    $this->projection->failure = provisioning_failure('runtime');

    expect(fn () => $this->provisioner->complete($this->instance, null))
        ->toThrow(RuntimeConvergenceException::class);
    expect($this->instance->refresh()->provisioning_step)->toBe('url-configured');

    $this->projection->failure = null;
    $this->provisioner->reserve($this->instance, null);
    $this->provisioner->complete($this->instance, null);

    expect($this->configuration->configurations)
        ->toBe(1)
        ->and($this->projection->convergences)
        ->toBe(2)
        ->and($this->instance->routes()->count())
        ->toBe(1);
});

it('leaves non-Laravel source unchanged while completing its Route', function (): void {
    $this->configuration->laravel = false;
    $this->provisioner->reserve($this->instance, null);

    $result = $this->provisioner->complete($this->instance, null);

    expect($result->status)
        ->toBe(AppInstanceState::Active)
        ->and($this->configuration->configurations)
        ->toBe(0)
        ->and($this->projection->convergences)
        ->toBe(1)
        ->and($result->routes()->sole()->status)
        ->toBe(RouteStatus::Active);
});

it('reports and resumes each runtime projection failure boundary without persisting it', function (
    string $step,
    string $errorCode,
): void {
    $this->provisioner->reserve($this->instance, null);
    $routeId = $this->instance->routes()->sole()->id;
    $this->projection->failure = new RuntimeConvergenceException(
        step: $step,
        errorCode: $errorCode,
        message: "The {$step} boundary failed.",
    );

    expect(fn () => $this->provisioner->complete($this->instance, null))
        ->toThrow(RuntimeConvergenceException::class);
    expect($this->instance->refresh()->provisioning_step)
        ->toBe('url-configured')
        ->and($this->instance->error_code)
        ->toBeNull()
        ->and($this->instance->routes()->sole()->only(['id', 'status', 'failed_step', 'error_code']))
        ->toBe([
            'id' => $routeId,
            'status' => RouteStatus::Pending,
            'failed_step' => null,
            'error_code' => null,
        ]);

    $this->projection->failure = null;
    $this->provisioner->reserve($this->instance, null);
    $result = $this->provisioner->complete($this->instance, null);

    expect($result->status)
        ->toBe(AppInstanceState::Active)
        ->and($result->routes()->sole()->id)
        ->toBe($routeId)
        ->and($result->routes()->count())
        ->toBe(1)
        ->and($this->configuration->configurations)
        ->toBe(1)
        ->and($this->projection->convergences)
        ->toBe(2);
})->with([
    'runtime' => ['php-fpm-config', 'app-dev.php_fpm_config_failed'],
    'certificate' => ['certificate-publish', 'app-dev.certificate_publish_failed'],
    'firewall' => ['route-firewall', 'app-dev.route_firewall_failed'],
    'publication' => ['private-dns', 'app-dev.dns_config_failed'],
]);

function provisioning_failure(string $step): RuntimeConvergenceException
{
    return new RuntimeConvergenceException(
        step: $step,
        errorCode: "app-dev.{$step}_failed",
        message: "The {$step} boundary failed.",
    );
}
