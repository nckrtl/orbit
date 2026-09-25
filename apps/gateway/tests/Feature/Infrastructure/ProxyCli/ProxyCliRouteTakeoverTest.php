<?php

declare(strict_types=1);

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Certificates\GatewayCertificatePaths;
use App\Domain\ProxyCli\ProxyCliState;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteRemovalProjector;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildResult;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Nodes\CaddyPackageSourceProgram;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\ProxyCli\NativeProxyCliPublicationManager;
use App\Infrastructure\ProxyCli\ProxyCliCertificatePublisher;
use App\Infrastructure\ProxyCli\ProxyCliRouteTakeover;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\Route;
use Tests\Support\FakeRouteRemovalProjector;

beforeEach(function (): void {
    $this->removal = new FakeRouteRemovalProjector;
    app()->instance(RouteRemovalProjector::class, $this->removal);
    $this->node = Node::query()->create([
        'name' => 'services',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.17',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.17',
    ]);
    $this->management = proxycli_takeover_route($this->node, 'cli-proxy-api.orbit', 'http://127.0.0.1:8317');
    $this->collector = proxycli_takeover_route($this->node, 'collector.cli-proxy-api.orbit', 'http://127.0.0.1:8787');
    app(ProxyCliState::class)->enable($this->node->id, 'cache', 'http://127.0.0.1:8317', 'management', 'read', 'control');
});

it('swaps the Route site for the collector site in one Node Caddy build, then removes the Route', function (): void {
    $events = [];
    $manager = proxycli_takeover_manager($events);

    $manager->converge($this->node, takeover: $this->collector);

    $builds = collect($events)->where('event', 'build')->values();

    expect(collect($events)->pluck('event')->all())
        ->toBe(['ssh:caddy-source', 'certificate:issue', 'ssh:certificate', 'build', 'dns:converge'])
        ->and($builds)->toHaveCount(1)
        ->and($builds[0]['caddyfile'])->toContain('# orbit: proxycli collector.cli-proxy-api.orbit')
        ->toContain('https://cli-proxy-api.orbit {')
        ->not->toContain('https://collector.cli-proxy-api.orbit {')
        ->and($builds[0]['collector_route'])->toBe(['status' => RouteStatus::Retiring, 'sites_published' => false])
        ->and($this->removal->events)->toBe(['dns', 'caddy', 'certificates', 'firewall'])
        ->and($this->removal->routeIds)->each->toBe($this->collector->id)
        ->and(Route::query()->whereKey($this->collector->id)->exists())->toBeFalse()
        ->and(Route::query()->whereKey($this->management->id)->exists())->toBeTrue();
});

it('restores the Route and keeps it when the build fails', function (): void {
    $events = [];
    $manager = proxycli_takeover_manager($events, failBuild: true);

    expect(fn () => $manager->converge($this->node, takeover: $this->collector))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('proxycli.caddy_publication_failed'));

    $route = $this->collector->refresh();

    expect(collect($events)->pluck('event')->all())
        ->toBe(['ssh:caddy-source', 'certificate:issue', 'ssh:certificate', 'build'])
        ->and($route->status)->toBe(RouteStatus::Active)
        ->and($route->sites_published)->toBeTrue()
        ->and($this->removal->events)->toBe([]);
});

it('refuses a Route that stopped matching before the takeover and changes nothing', function (): void {
    $events = [];
    $manager = proxycli_takeover_manager($events);
    $this->collector->customProxy()->update(['upstream' => 'http://127.0.0.1:9000']);

    expect(fn () => $manager->converge($this->node, takeover: $this->collector))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('proxycli.hostname_taken'));

    expect(collect($events)->pluck('event')->all())->not->toContain('build')
        ->and($this->collector->refresh()->status)->toBe(RouteStatus::Active)
        ->and($this->removal->events)->toBe([]);
});

it('builds the Node once without a takeover when no Route holds the name', function (): void {
    $events = [];
    $manager = proxycli_takeover_manager($events);
    $this->collector->delete();

    $manager->converge($this->node);

    $builds = collect($events)->where('event', 'build')->values();

    expect($builds)->toHaveCount(1)
        ->and($builds[0]['caddyfile'])->toContain('# orbit: proxycli collector.cli-proxy-api.orbit')
        ->and($this->removal->events)->toBe([]);
});

function proxycli_takeover_route(Node $node, string $domain, string $upstream): Route
{
    $route = Route::query()->create([
        'kind' => RouteKind::CustomProxy,
        'node_id' => $node->id,
        'domain' => $domain,
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->customProxy()->create(['node_id' => $node->id, 'upstream' => $upstream]);
    $route->update(['status' => RouteStatus::Active]);

    return $route->refresh();
}

/**
 * @param  list<array<string, mixed>>  $events
 */
function proxycli_takeover_manager(array &$events, bool $failBuild = false): NativeProxyCliPublicationManager
{
    $certificateDirectory = sys_get_temp_dir().'/orbit-proxycli-takeover-'.bin2hex(random_bytes(4));
    mkdir($certificateDirectory);
    file_put_contents($certificateDirectory.'/proxycli.pem', "CERT\n");
    file_put_contents($certificateDirectory.'/proxycli.key', "KEY\n");

    return new NativeProxyCliPublicationManager(
        certificates: new class($events, $certificateDirectory) implements GatewayCertificateIssuer
        {
            public function __construct(private array &$events, private string $directory) {}

            public function issue(string $hostname, string $wireguardIp): GatewayCertificatePaths
            {
                $this->events[] = ['event' => 'certificate:issue'];

                return new GatewayCertificatePaths(
                    privateKeyPath: $this->directory.'/proxycli.key',
                    certificatePath: $this->directory.'/proxycli.pem',
                );
            }
        },
        certificatePublisher: new ProxyCliCertificatePublisher,
        builds: new class($events, $failBuild) implements NodeCaddyBuilds
        {
            public function __construct(private array &$events, private bool $fail) {}

            public function build(Node $node): NodeCaddyBuildResult
            {
                $route = Route::query()->where('domain', 'collector.cli-proxy-api.orbit')->first();
                $caddyfile = app(NodeCaddyfileRenderer::class)->render($node);
                $this->events[] = [
                    'event' => 'build',
                    'caddyfile' => $caddyfile->content,
                    'problems' => $caddyfile->problems,
                    'collector_route' => $route instanceof Route
                        ? ['status' => $route->status, 'sites_published' => $route->sites_published]
                        : null,
                ];

                if ($this->fail || ! $caddyfile->buildable()) {
                    throw new NodeCaddyBuildException($node->name, 'validate', implode(' ', $caddyfile->problems) ?: 'Error: test failure');
                }

                return NodeCaddyBuildResult::Published;
            }

            public function checkListenAddresses(Node $node): void {}
        },
        dns: new class($events) implements PrivateDnsManager
        {
            public function __construct(private array &$events) {}

            public function converge(?Node $pendingNode = null): void
            {
                $this->events[] = ['event' => 'dns:converge'];
            }
        },
        ssh: new class($events) implements SshExecutor
        {
            public function __construct(private array &$events) {}

            public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
            {
                if (in_array(CaddyPackageSourceProgram::SOURCE_URI, $command->arguments, true)) {
                    $this->events[] = ['event' => 'ssh:caddy-source'];

                    return new CommandResult(0, '', '', 1, false);
                }

                $this->events[] = ['event' => $command->protectedInput !== null ? 'ssh:certificate' : 'ssh:other'];

                return new CommandResult(0, '', '', 1, false);
            }
        },
        keys: new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/tmp/orbit-test-key';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 AAAA test';
            }
        },
        knownHosts: new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/tmp/orbit-test-known-hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
        takeover: app(ProxyCliRouteTakeover::class),
    );
}
