<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\ProductionPhpRuntimeIdentity;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppInstances\ProductionPhpRuntimeConfigRenderer;
use App\Infrastructure\AppInstances\RemoteProductionPhpRuntimeManager;
use App\Infrastructure\AppProd\AppProdSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use Tests\Support\AppDevFakeSshExecutor;

it('refreshes only the recorded runtime through its exact socket and verifies completion', function (): void {
    $node = orb215_cache_node();
    [$instance, $identity] = orb215_cache_instance($node, 'selected');
    [, $controlIdentity] = orb215_cache_instance($node, 'control');
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, "COMPLETE\n", '', 25, false),
    ]);
    $manager = orb215_cache_manager($ssh);

    $manager->refreshCache($instance);

    expect($ssh->commands)
        ->toHaveCount(1)
        ->and($ssh->commands[0]->arguments)
        ->toBe([
            'sudo',
            'bash',
            '-seu',
            '--',
            'refresh-cache',
            $identity->user,
            $identity->home,
            $identity->version,
            $identity->service,
            $identity->pool,
            $identity->socket,
            $identity->markerPath,
            '/run/lock/orbit',
            base64_encode($identity->marker()),
            '30',
        ])
        ->not->toContain($controlIdentity->socket, $controlIdentity->service)
        ->and($ssh->connections)
        ->toHaveCount(1)
        ->and($ssh->connections[0]->host)
        ->toBe('10.44.0.215');

    $script = $ssh->commands[0]->input ?? '';

    expect($script)
        ->toContain(
            'flock -w 30',
            'cmp -s -- "$expected_marker" "$marker_path"',
            'systemctl is-active --quiet "$service"',
            'systemctl show --property=MainPID --value "$service"',
            'test -S "$socket"',
            'SCRIPT_FILENAME',
            'opcache_reset()',
            'PHP_SAPI',
            'posix_getppid()',
            'raise CacheFailure(44 if reset_accepted else 70)',
            'waiting = observed[\'restart_pending\'] or observed[\'restart_in_progress\']',
            'install -o root -g root -m 0444',
            'test "$master_after" = "$master_before"',
            "printf 'COMPLETE\\n'",
            'trap cleanup EXIT',
            'rm -f -- "$expected_marker" "$probe_source" "$client" "$probe"',
            'rmdir -- "$probe_directory"',
        )
        ->not->toContain(
            'php -r',
            'php8.5 -r',
            'systemctl reload',
            'systemctl restart',
            'systemctl reload-or-restart',
            'systemctl try-restart',
            'find /run/php',
            'ls /run/php',
            '/run/php/*.sock',
            $controlIdentity->socket,
            $controlIdentity->service,
        );
});

it('refuses an invalid recorded identity before opening transport', function (): void {
    [$instance] = orb215_cache_instance(orb215_cache_node(), 'invalid');
    $instance->update(['production_php_socket' => '/run/php/another-runtime.sock']);
    $ssh = new AppDevFakeSshExecutor;
    $manager = orb215_cache_manager($ssh);

    expect(fn () => $manager->refreshCache($instance->refresh()))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('app-prod.php_runtime_identity_invalid');
        });
    expect($ssh->commands)->toBe([]);
});

it('maps each bounded cache failure to its distinct error code', function (
    int $exitCode,
    string $expectedErrorCode,
): void {
    [$instance, $identity] = orb215_cache_instance(orb215_cache_node(), "failure-{$exitCode}");
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult($exitCode, '', 'private remote detail', 50, false),
    ]);
    $manager = orb215_cache_manager($ssh);

    expect(fn () => $manager->refreshCache($instance))
        ->toThrow(function (RuntimeConvergenceException $exception) use ($expectedErrorCode): void {
            expect($exception->step)
                ->toBe('app-prod-php-cache-refresh')
                ->and($exception->errorCode)
                ->toBe($expectedErrorCode)
                ->and($exception->getMessage())
                ->not->toContain('private remote detail');
        });
    expect($ssh->commands)
        ->toHaveCount(1)
        ->and($ssh->commands[0]->arguments)
        ->toContain($identity->socket);
})->with([
    'unavailable socket' => [41, 'app-prod.php_cache_socket_unavailable'],
    'wrong service association' => [42, 'app-prod.php_cache_association_invalid'],
    'reset rejected' => [43, 'app-prod.php_cache_reset_rejected'],
    'reset pending at deadline' => [44, 'app-prod.php_cache_reset_pending'],
]);

it('retains generic transport and protocol failures separately from bounded cache outcomes', function (int $exitCode): void {
    [$instance] = orb215_cache_instance(orb215_cache_node(), 'transport');
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult($exitCode, '', 'private transport detail', 50, false),
    ]);
    $manager = orb215_cache_manager($ssh);

    expect(fn () => $manager->refreshCache($instance))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->step)
                ->toBe('app-prod-php-cache-refresh')
                ->and($exception->errorCode)
                ->toBe('app-prod.php_cache_refresh_failed')
                ->and($exception->getMessage())
                ->not->toContain('private transport detail');
        });
})->with([
    'remote protocol failure' => 70,
    'SSH transport failure' => 255,
]);

it('refuses malformed zero-exit completion evidence', function (
    string $stdout,
    string $stderr,
    bool $truncated,
): void {
    [$instance] = orb215_cache_instance(orb215_cache_node(), 'malformed');
    $ssh = new AppDevFakeSshExecutor([
        new CommandResult(0, $stdout, $stderr, 25, $truncated),
    ]);
    $manager = orb215_cache_manager($ssh);

    expect(fn () => $manager->refreshCache($instance))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('app-prod.php_cache_reset_rejected');
        });
})->with([
    'missing receipt' => ['', '', false],
    'receipt without final newline' => ['COMPLETE', '', false],
    'additional receipt output' => ["COMPLETE\nEXTRA\n", '', false],
    'stderr output' => ["COMPLETE\n", 'unexpected', false],
    'truncated output' => ["COMPLETE\n", '', true],
]);

function orb215_cache_node(): Node
{
    return Node::query()->create([
        'name' => 'php-cache',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.215',
        'wireguard_ip' => '10.44.0.215',
        'user' => 'orbit',
    ]);
}

/** @return array{AppInstance, ProductionPhpRuntimeIdentity} */
function orb215_cache_instance(Node $node, string $suffix): array
{
    $app = OrbitApp::query()->create([
        'name' => "PHP cache {$suffix}",
        'slug' => "php-cache-{$suffix}",
        'repository_url' => "https://example.test/php-cache-{$suffix}.git",
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $user = "orbit-app-{$app->id}";
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'primary',
        'environment' => 'production',
        'checkout_path' => "/home/{$user}",
        'production_user' => $user,
        'production_home' => "/home/{$user}",
        'root' => 'public',
        'selected_php_version' => '8.5',
    ]);
    $identity = ProductionPhpRuntimeIdentity::forProvisioning($instance, '8.5');
    $instance->update($identity->attributes());

    return [$instance->refresh(), $identity];
}

function orb215_cache_manager(AppDevFakeSshExecutor $ssh): RemoteProductionPhpRuntimeManager
{
    $keys = new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/tmp/orbit-test-key';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 AAAA';
        }
    };
    $knownHosts = new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/tmp/orbit-test-known-hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };

    return new RemoteProductionPhpRuntimeManager(
        renderer: new ProductionPhpRuntimeConfigRenderer,
        ssh: new AppProdSshExecutor($ssh, $keys, $knownHosts),
    );
}
