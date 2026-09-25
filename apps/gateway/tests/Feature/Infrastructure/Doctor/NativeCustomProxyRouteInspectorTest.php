<?php

declare(strict_types=1);

use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Caddy\CaddyGlobalOptions;
use App\Infrastructure\Doctor\NativeCustomProxyRouteInspector;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Support\Facades\File;
use Tests\Support\LocalRootShellSshExecutor;

beforeEach(function (): void {
    $node = Node::query()->create([
        'name' => 'custom-proxy-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.60',
        'wireguard_ip' => '10.44.0.60',
        'user' => 'orbit',
    ]);
    $route = Route::query()->create([
        'kind' => RouteKind::CustomProxy,
        'node_id' => $node->id,
        'domain' => 'executor.orbit',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->customProxy()->create(['node_id' => $node->id, 'upstream' => 'http://127.0.0.1:4788']);
    $this->proxy = $route->customProxy()->sole();
    $this->caddy = sys_get_temp_dir().'/orbit-custom-proxy-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists("{$this->caddy}/orbit-versions/v1");
    symlink("{$this->caddy}/orbit-versions/v1/Caddyfile", "{$this->caddy}/Caddyfile");
    file_put_contents("{$this->caddy}/orbit-versions/v1/Caddyfile", '');
    $this->ssh = new LocalRootShellSshExecutor("{$this->caddy}/orbit-versions");
});

afterEach(function (): void {
    File::deleteDirectory($this->caddy);
});

it('reads the root-only published Caddy version through sudo', function (): void {
    custom_proxy_inspector_build($this->caddy, "https://executor.orbit {\n    reverse_proxy 127.0.0.1:4788\n}\n");

    $observation = custom_proxy_inspector($this->ssh, $this->caddy)->inspect($this->proxy);

    expect(array_slice($this->ssh->commands[0]->arguments, 0, 3))->toBe(['sudo', 'bash', '-seu'])
        ->and($observation->caddyMatches)->toBeTrue();
});

it('reports a custom proxy site missing from the one Caddyfile a Node Caddy build writes', function (): void {
    custom_proxy_inspector_build($this->caddy, "reverb.orbit {\n    respond ok\n}\n");

    expect(custom_proxy_inspector($this->ssh, $this->caddy)->inspect($this->proxy)->caddyMatches)->toBeFalse();
});

it('does not read a site that the live file only imports from a fragment of an earlier layout', function (): void {
    File::ensureDirectoryExists("{$this->caddy}/orbit-versions/v1/fragments");
    file_put_contents("{$this->caddy}/orbit-versions/v1/Caddyfile", "import {$this->caddy}/orbit-versions/v1/fragments/*.caddy\n");
    file_put_contents("{$this->caddy}/orbit-versions/v1/fragments/app-dev.caddy", "https://executor.orbit {\n    reverse_proxy 127.0.0.1:4788\n}\n");

    expect(custom_proxy_inspector($this->ssh, $this->caddy)->inspect($this->proxy)->caddyMatches)->toBeFalse();
});

function custom_proxy_inspector(LocalRootShellSshExecutor $ssh, string $caddy): NativeCustomProxyRouteInspector
{
    return new NativeCustomProxyRouteInspector(
        new AppDevSshExecutor(
            $ssh,
            new class implements SshKeyProvider
            {
                public function privateKeyPath(): string
                {
                    return '/tmp/custom-proxy-test-key';
                }

                public function publicKey(): string
                {
                    return 'ssh-ed25519 test';
                }
            },
            new class implements KnownHostsStore
            {
                public function path(): string
                {
                    return '/tmp/custom-proxy-known-hosts';
                }

                public function put(string $host, int $port, HostKey $key): void {}
            },
        ),
        new CommandDeadline,
        liveCaddyfilePath: "{$caddy}/Caddyfile",
    );
}

/** Publishes the one versioned Caddyfile a Node Caddy build writes. */
function custom_proxy_inspector_build(string $caddy, string $sites): void
{
    File::ensureDirectoryExists("{$caddy}/orbit-versions/v2");
    file_put_contents("{$caddy}/orbit-versions/v2/Caddyfile", NodeCaddyfileRenderer::Marker."\n".CaddyGlobalOptions::render()."\n".$sites);
    unlink("{$caddy}/Caddyfile");
    symlink("{$caddy}/orbit-versions/v2/Caddyfile", "{$caddy}/Caddyfile");
}
