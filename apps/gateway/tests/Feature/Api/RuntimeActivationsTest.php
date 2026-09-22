<?php

declare(strict_types=1);

use App\Domain\Hibernation\AppInstanceCheckoutInspector;
use App\Domain\Hibernation\AppInstanceRuntimeReadiness;
use App\Domain\Hibernation\HibernationMarkerStore;
use App\Domain\Hibernation\HibernationWakeFailureStore;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Responses\RuntimeActivationPage;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeAppInstanceCheckoutInspector;
use Tests\Support\FakeAppInstanceRuntimeReadiness;
use Tests\Support\ProcessesApiFakeRuntimeManager;

beforeEach(function (): void {
    $this->runtime = new ProcessesApiFakeRuntimeManager;
    $this->markers = new class implements HibernationMarkerStore
    {
        /** @var list<string> */
        public array $awake = [];

        public function markAwake(Node $node, string $key): void
        {
            $this->awake[] = $key;
        }

        public function markAsleep(Node $node, string $key): void {}

        public function markCold(Node $node, string $key): void {}

        public function clearCold(Node $node, string $key): void {}

        public function lastActivityUnix(Node $node, string $key): ?int
        {
            return null;
        }

        public function isAwake(Node $node, string $key): bool
        {
            return in_array($key, $this->awake, true);
        }

        public function isCold(Node $node, string $key): bool
        {
            return false;
        }
    };
    app()->instance(ProcessRuntimeManager::class, $this->runtime);
    app()->instance(HibernationMarkerStore::class, $this->markers);
    app()->instance(AppInstanceRuntimeReadiness::class, new FakeAppInstanceRuntimeReadiness);
    app()->instance(AppInstanceCheckoutInspector::class, new FakeAppInstanceCheckoutInspector);

    $this->node = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.3',
    ]);
    $this->node->roles()->create(['role' => 'app-dev', 'status' => LifecycleStatus::Active]);
    $app = OrbitApp::query()->create([
        'name' => 'Docs',
        'slug' => 'docs',
        'repository_url' => 'git@example.test:docs.git',
    ]);
    $this->instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $this->node->id,
        'name' => 'main',
        'environment' => 'development',
        'checkout_path' => '/home/orbit/apps/docs',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
});

it('returns the Orbit progress page before it starts Processes', function (): void {
    $running = Process::query()->create([
        'owner_type' => AppInstance::class,
        'owner_id' => $this->instance->id,
        'name' => 'vite',
        'runtime' => 'systemd',
        'working_directory' => '/home/orbit/apps/docs',
        'runtime_config' => ['command' => ['/usr/bin/vp', 'run', 'dev']],
        'restart_policy' => 'always',
        'desired_state' => DesiredProcessState::Running,
        'status' => 'active',
    ]);

    $response = $this->get('/api/v1/runtime-activations/app-instance/'.$this->instance->id);

    assert_hibernation_boot_screen($response);
    $response->assertSee('<title>Docs</title>', false);

    expect($this->runtime->started)
        ->toBe([$running->id])
        ->and($this->markers->awake)
        ->toBe([RuntimeHibernation::key((int) $this->instance->id)]);
});

it('refuses wake from a different Node', function (): void {
    $stranger = Node::query()->create([
        'name' => 'other',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.21',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.9',
    ]);

    $this->withServerVariables(['REMOTE_ADDR' => $stranger->wireguard_ip])
        ->getJson('/api/v1/runtime-activations/app-instance/'.$this->instance->id)
        ->assertForbidden();

    expect($this->runtime->started)->toBe([]);
});

it('returns an HTML failure page for an ineligible production AppInstance', function (): void {
    $this->instance->update(['environment' => 'production', 'production_user' => 'orbit-docs', 'production_home' => '/var/www/docs']);
    $this->node->roles()->where('role', 'app-dev')->delete();
    $this->node->roles()->create(['role' => 'app-prod', 'status' => LifecycleStatus::Active]);
    $this->instance->unsetRelation('node');

    $response = $this->get('/api/v1/runtime-activations/app-instance/'.$this->instance->id);

    assert_hibernation_failure_screen($response, '/?orbit-wake-retry=1');

    $response
        ->assertSee('AppInstance [main] is not an app-dev development target.', false)
        ->assertDontSee('<script', false);
});

it('returns an HTML progress page when another wake holds the AppInstance lock', function (): void {
    $this->mock(ProcessAdmissionLock::class, function ($mock): void {
        $mock->shouldReceive('run')->once()->andThrow(new ResourceOperationException(
            errorCode: 'process.operation_busy',
            message: 'Another Process operation is active for this AppInstance. Retry the request.',
            status: 409,
        ));
    });

    assert_hibernation_boot_screen(
        $this->get('/api/v1/runtime-activations/app-instance/'.$this->instance->id),
    );
});

it('returns an HTML failure page on the next intercept when Process start fails', function (): void {
    Process::query()->create([
        'owner_type' => AppInstance::class,
        'owner_id' => $this->instance->id,
        'name' => 'vite',
        'runtime' => 'systemd',
        'working_directory' => '/home/orbit/apps/docs',
        'runtime_config' => ['command' => ['/usr/bin/vp', 'run', 'dev']],
        'restart_policy' => 'always',
        'desired_state' => DesiredProcessState::Running,
        'status' => 'active',
    ]);
    $this->runtime->failStartDuringCall = true;

    assert_hibernation_boot_screen(
        $this->get('/api/v1/runtime-activations/app-instance/'.$this->instance->id),
    );

    $failed = $this->get('/api/v1/runtime-activations/app-instance/'.$this->instance->id);

    assert_hibernation_failure_screen($failed, '/?orbit-wake-retry=1');

    $failed->assertSee('The process did not start.', false);
});

it('polls the forwarded path and ignores an off-site address', function (): void {
    $response = $this->get('/api/v1/runtime-activations/app-instance/'.$this->instance->id, [
        'X-Forwarded-Uri' => '/docs?tab=1',
    ]);

    assert_hibernation_boot_screen($response);

    expect($response->getContent())
        ->toContain('"\/docs?tab=1"')
        ->not->toContain('orbit-wake-retry');

    $unsafe = $this->get('/api/v1/runtime-activations/app-instance/'.$this->instance->id, [
        'X-Forwarded-Uri' => 'https://evil.example/phish',
    ]);

    expect($unsafe->getContent())->toContain('"\/"');
});

it('escapes the site name and the stored failure on the boot screen', function (): void {
    $this->instance->update([
        'registration_route_domain' => '"><script>alert(1)</script>',
    ]);

    $progress = $this->get('/api/v1/runtime-activations/app-instance/'.$this->instance->id);

    assert_hibernation_boot_screen($progress);

    expect($progress->getContent())
        ->toContain('&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;')
        ->not->toContain('"><script>alert(1)</script>');

    app(HibernationWakeFailureStore::class)->remember(
        (int) $this->instance->id,
        '<img src=x onerror=alert(1)>',
    );

    $failed = $this->get('/api/v1/runtime-activations/app-instance/'.$this->instance->id);

    assert_hibernation_failure_screen($failed, '/?orbit-wake-retry=1');

    expect($failed->getContent())
        ->toContain('&lt;img src=x onerror=alert(1)&gt;')
        ->not->toContain('<img src=x onerror=alert(1)>')
        ->not->toContain('<script');
});

function assert_hibernation_boot_screen(TestResponse $response): void
{
    $response
        ->assertStatus(401)
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Retry-After', '1')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader(RuntimeActivationPage::ACTIVATION_STATE_HEADER, RuntimeActivationPage::STATE_PENDING)
        ->assertDontSee('http-equiv="refresh"', false)
        ->assertSee('orbit-spin', false)
        ->assertSee('0.325s', false)
        ->assertSee('will-change: transform', false)
        ->assertSee('conic-gradient', false)
        ->assertSee('logo-rotor', false)
        ->assertSee('logo-track', false)
        ->assertSee('role="img"', false)
        ->assertSee('aria-label="Orbit"', false)
        ->assertSee('0.0000%', false)
        ->assertSee('0.6165%', false)
        ->assertSee('28.3909%', false)
        ->assertSee('50.0000%', false)
        ->assertSee('100.0000%', false)
        ->assertSee('fetch(', false)
        ->assertSee("redirect: 'manual'", false)
        ->assertSee('opaqueredirect', false)
        ->assertSee('const intervalMs = 1000', false)
        ->assertDontSee('setInterval', false);

    $content = (string) $response->getContent();

    expect(substr_count($content, 'translate3d(-50%, -50%, 0) rotate'))
        ->toBe(130)
        ->and(preg_match_all('/\d+(?:\.\d+)?%\s*\{\s*transform:\s*translate3d/', $content))
        ->toBe(129)
        ->and(substr_count($content, 'setTimeout'))
        ->toBe(1)
        ->and(substr_count($content, '<script'))
        ->toBe(1);

    $csp = (string) $response->headers->get('Content-Security-Policy');

    expect($csp)
        ->toContain("default-src 'none'")
        ->toContain("style-src 'unsafe-inline'")
        ->toContain('img-src data:')
        ->toContain("connect-src 'self'")
        ->toContain("base-uri 'none'")
        ->toContain("frame-ancestors 'none'")
        ->toContain("script-src 'nonce-")
        ->not->toContain("script-src 'unsafe-inline'");
}

function assert_hibernation_failure_screen(TestResponse $response, string $retryUrl): void
{
    $response
        ->assertStatus(503)
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader(RuntimeActivationPage::ACTIVATION_STATE_HEADER, RuntimeActivationPage::STATE_FAILED)
        ->assertDontSee('http-equiv="refresh"', false)
        ->assertSee('orbit-spin', false)
        ->assertSee('Try again', false)
        ->assertSee('href="'.$retryUrl.'"', false)
        ->assertDontSee('setTimeout', false)
        ->assertDontSee('fetch(', false)
        ->assertDontSee('<script', false);

    expect($response->headers->has('Retry-After'))->toBeFalse();

    $csp = (string) $response->headers->get('Content-Security-Policy');

    expect($csp)
        ->toContain("default-src 'none'")
        ->toContain("style-src 'unsafe-inline'")
        ->toContain("base-uri 'none'")
        ->toContain("frame-ancestors 'none'")
        ->not->toContain('script-src')
        ->not->toContain('connect-src');
}
