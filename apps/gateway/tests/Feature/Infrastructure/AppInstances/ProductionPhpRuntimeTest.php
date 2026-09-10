<?php

declare(strict_types=1);

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\ProductionPhpRuntimeIdentity;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Infrastructure\AppDev\AppDevCaddyConfigRenderer;
use App\Infrastructure\AppDev\AppDevPhpFpmConfigRenderer;
use App\Infrastructure\AppDev\AppDevSiteRepository;
use App\Infrastructure\AppInstances\ProductionPhpRuntimeConfigRenderer;
use App\Infrastructure\AppInstances\RemoteProductionPhpRuntimeManager;
use App\Infrastructure\AppProd\AppProdSshExecutor;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AppDevFakeSshExecutor;

it('records a canonical dedicated PHP runtime identity without converting existing placements', function (): void {
    $app = OrbitApp::query()->create([
        'name' => 'Dedicated PHP',
        'slug' => 'dedicated-php',
        'repository_url' => 'https://example.test/dedicated-php.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $node = Node::query()->create([
        'name' => 'dedicated-php',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.214',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'primary',
        'environment' => 'production',
        'checkout_path' => "/home/orbit-app-{$app->id}",
        'production_user' => "orbit-app-{$app->id}",
        'production_home' => "/home/orbit-app-{$app->id}",
        'root' => 'public',
        'selected_php_version' => '8.5',
    ]);

    expect(Schema::hasColumns('app_instances', [
        'production_php_service',
        'production_php_pool',
        'production_php_socket',
    ]))
        ->toBeTrue()
        ->and($instance->production_php_service)
        ->toBeNull()
        ->and($instance->production_php_pool)
        ->toBeNull()
        ->and($instance->production_php_socket)
        ->toBeNull();

    $identity = ProductionPhpRuntimeIdentity::forProvisioning($instance, '8.5');
    $instance->update($identity->attributes());

    expect($identity->service)
        ->toBe("orbit-orbit-app-{$app->id}-php8.5-fpm.service")
        ->and($identity->pool)
        ->toBe("orbit-orbit-app-{$app->id}")
        ->and($identity->socket)
        ->toBe("/run/php/orbit-app-{$app->id}.sock")
        ->and($identity->runtimeDirectory)
        ->toBe("/etc/orbit/php-fpm/orbit-app-{$app->id}")
        ->and(ProductionPhpRuntimeIdentity::from($instance->refresh()))
        ->toEqual($identity);
});

it('refuses a stored runtime association that differs from its production identity', function (): void {
    $app = OrbitApp::query()->create([
        'name' => 'Conflicting PHP',
        'slug' => 'conflicting-php',
        'repository_url' => 'https://example.test/conflicting-php.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $node = Node::query()->create([
        'name' => 'conflicting-php',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.215',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'primary',
        'environment' => 'production',
        'checkout_path' => "/home/orbit-app-{$app->id}",
        'production_user' => "orbit-app-{$app->id}",
        'production_home' => "/home/orbit-app-{$app->id}",
        'root' => 'public',
        'selected_php_version' => '8.5',
        'production_php_service' => 'php8.5-fpm.service',
        'production_php_pool' => "orbit-orbit-app-{$app->id}",
        'production_php_socket' => "/run/php/orbit-app-{$app->id}.sock",
    ]);

    expect(fn () => ProductionPhpRuntimeIdentity::from($instance))
        ->toThrow(\App\Domain\Shared\ResourceOperationException::class);
});

it('renders generated identity separately from preserved local defaults', function (): void {
    $identity = new ProductionPhpRuntimeIdentity(
        user: 'orbit-app-9',
        home: '/home/orbit-app-9',
        version: '8.5',
        service: 'orbit-orbit-app-9-php8.5-fpm.service',
        pool: 'orbit-orbit-app-9',
        socket: '/run/php/orbit-app-9.sock',
        documentRoot: '/home/orbit-app-9/public',
    );
    $rendered = new ProductionPhpRuntimeConfigRenderer()->render($identity);

    expect($rendered->main)
        ->toContain(
            'pid = /run/php/orbit-app-9.pid',
            'include = /etc/orbit/php-fpm/orbit-app-9/generated/pool.conf',
            'include = /etc/orbit/php-fpm/orbit-app-9/local.conf',
        )
        ->and($rendered->pool)
        ->toContain(
            '[orbit-orbit-app-9]',
            'user = orbit-app-9',
            'listen = /run/php/orbit-app-9.sock',
            'chdir = /home/orbit-app-9',
            'env[HOME] = /home/orbit-app-9',
        )
        ->and($rendered->localDefaults)
        ->toContain(
            '[orbit-orbit-app-9]',
            'pm = ondemand',
            'opcache.memory_consumption] = 256',
            'opcache.validate_timestamps] = 0',
        )
        ->not
        ->toContain('user =', 'listen =', 'chdir =', 'include =')
        ->and($rendered->unit)
        ->toContain(
            'ExecStart=/usr/sbin/php-fpm8.5 --nodaemonize --fpm-config /etc/orbit/php-fpm/orbit-app-9/generated/php-fpm.conf',
            'PIDFile=/run/php/orbit-app-9.pid',
        );
});

it('publishes and removes only the recorded service while preserving local tuning', function (): void {
    [$instance, $node] = orb214_runtime_instance();
    $identity = ProductionPhpRuntimeIdentity::forProvisioning($instance, '8.5');
    $instance->update($identity->attributes());
    $ssh = new AppDevFakeSshExecutor;
    $manager = new RemoteProductionPhpRuntimeManager(
        renderer: new ProductionPhpRuntimeConfigRenderer,
        ssh: orb214_app_prod_ssh($ssh),
    );

    $manager->converge($instance->refresh());
    $manager->remove($instance->refresh());

    $publish = collect($ssh->commands)
        ->first(static fn ($command): bool => in_array('converge', $command->arguments, true));
    $remove = collect($ssh->commands)
        ->first(static fn ($command): bool => in_array('remove', $command->arguments, true));

    expect($publish?->arguments)
        ->toContain(
            $identity->service,
            $identity->pool,
            $identity->socket,
            $identity->runtimeDirectory,
        )
        ->and($publish?->input)
        ->toContain(
            'if [ ! -e "$local_tuning" ]',
            'local_before=$(sha256sum -- "$local_tuning"',
            'cp -- "$work_directory/php-fpm.conf" "$work_directory/php-fpm.validate.conf"',
            'local_after=$(sha256sum -- "$local_tuning"',
            'test "$local_before" = "$local_after"',
            'if [ "$was_active" = 1 ] && [ "$runtime_changed" = 0 ]; then',
            'systemctl restart "$service"',
            'systemctl enable --now "$service"',
            'systemctl is-active --quiet "$service"',
            'test -S "$socket"',
        )
        ->not->toContain('php$version-fpm.service')->and($remove?->input)->toContain(
            'orbit-"$user"-php*-fpm.service) ;;',
            'systemctl disable --now "$service"',
            'rm -rf -- "$generated_directory"',
            'rm -f -- "$unit_path"',
            'test -f "$local_tuning"',
        )
        ->not->toContain('rm -f -- "$local_tuning"', 'rm -rf -- "$runtime_directory"');

    expect($node->wireguard_ip)->toBe('10.44.0.214');
});

it('keeps a dedicated production route in Caddy and out of shared FPM publication', function (): void {
    [$dedicated, $node] = orb214_runtime_instance();
    $dedicatedIdentity = ProductionPhpRuntimeIdentity::forProvisioning($dedicated, '8.5');
    $dedicated->update([
        ...$dedicatedIdentity->attributes(),
        'status' => AppInstanceState::SourceResolved,
    ]);
    $dedicatedRoute = Route::query()->create([
        'app_id' => $dedicated->app_id,
        'node_id' => $node->id,
        'generation_basis_node_id' => $node->id,
        'hostname' => 'dedicated.example.test',
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $dedicatedRoute->targets()->create(['app_instance_id' => $dedicated->id, 'position' => 0]);
    $dedicatedRoute->update(['status' => RouteStatus::Active]);

    $sharedApp = OrbitApp::query()->create([
        'name' => 'Shared PHP',
        'slug' => 'shared-php',
        'repository_url' => 'https://example.test/shared-php.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $shared = AppInstance::query()->create([
        'app_id' => $sharedApp->id,
        'node_id' => $node->id,
        'name' => 'primary',
        'environment' => 'production',
        'checkout_path' => "/home/orbit-app-{$sharedApp->id}",
        'production_user' => "orbit-app-{$sharedApp->id}",
        'production_home' => "/home/orbit-app-{$sharedApp->id}",
        'root' => 'public',
        'selected_php_version' => '8.5',
        'status' => AppInstanceState::SourceResolved,
    ]);
    $sharedRoute = Route::query()->create([
        'app_id' => $shared->app_id,
        'node_id' => $node->id,
        'generation_basis_node_id' => $node->id,
        'hostname' => 'shared.example.test',
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $sharedRoute->targets()->create(['app_instance_id' => $shared->id, 'position' => 0]);
    $sharedRoute->update(['status' => RouteStatus::Active]);

    $sites = new AppDevSiteRepository()->forNode($node);
    $caddy = new AppDevCaddyConfigRenderer()->render($sites);
    $sharedFpm = new AppDevPhpFpmConfigRenderer()->render(
        $sites->reject->usesDedicatedPhpRuntime()->values(),
        new ManagedUserAccount('orbit', 'orbit', '/home/orbit'),
    );

    expect($sites)
        ->toHaveCount(2)
        ->and($caddy)
        ->toContain(
            "php_fastcgi unix/{$dedicatedIdentity->socket}",
            "php_fastcgi unix//run/php/orbit-app-instance-{$shared->id}.sock",
        )
        ->and($sharedFpm)
        ->toContain("[orbit-app-instance-{$shared->id}]")
        ->not->toContain(
            "[orbit-app-instance-{$dedicated->id}]",
            $dedicatedIdentity->socket,
        );
});

/** @return array{AppInstance, Node} */
function orb214_runtime_instance(): array
{
    $app = OrbitApp::query()->create([
        'name' => 'Runtime fixture',
        'slug' => 'runtime-fixture',
        'repository_url' => 'https://example.test/runtime-fixture.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $node = Node::query()->create([
        'name' => 'runtime-fixture',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.216',
        'wireguard_ip' => '10.44.0.214',
        'user' => 'orbit',
    ]);
    $user = "orbit-app-{$app->id}";

    return [
        AppInstance::query()->create([
            'app_id' => $app->id,
            'node_id' => $node->id,
            'name' => 'primary',
            'environment' => 'production',
            'checkout_path' => "/home/{$user}",
            'production_user' => $user,
            'production_home' => "/home/{$user}",
            'root' => 'public',
            'selected_php_version' => '8.5',
        ]),
        $node,
    ];
}

function orb214_app_prod_ssh(AppDevFakeSshExecutor $ssh): AppProdSshExecutor
{
    $keys = new class implements SshKeyProvider {
        public function privateKeyPath(): string
        {
            return '/tmp/orbit-test-key';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 AAAA';
        }
    };
    $knownHosts = new class implements KnownHostsStore {
        public function path(): string
        {
            return '/tmp/orbit-test-known-hosts';
        }

        public function put(string $host, int $port, \App\Infrastructure\Ssh\HostKey $key): void {}
    };

    return new AppProdSshExecutor($ssh, $keys, $knownHosts);
}
