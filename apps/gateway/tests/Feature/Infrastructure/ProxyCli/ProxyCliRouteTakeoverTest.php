<?php

declare(strict_types=1);

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Certificates\GatewayCertificatePaths;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteRemovalProjector;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\CaddyPackageSourceProgram;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\ProxyCli\NativeProxyCliPublicationManager;
use App\Infrastructure\ProxyCli\ProxyCliCaddyPublisher;
use App\Infrastructure\ProxyCli\ProxyCliCaddySiteRenderer;
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
});

it('withdraws the Route site in the collector Caddy reload, then removes the Route', function (): void {
    $events = [];
    $manager = proxycli_takeover_manager($events);

    $manager->converge($this->node, takeover: $this->collector);

    $caddy = collect($events)->where('event', 'ssh:caddy')->values();

    expect(collect($events)->pluck('event')->all())
        ->toBe(['ssh:caddy-source', 'certificate:issue', 'ssh:certificate', 'ssh:caddy', 'dns:converge'])
        ->and($caddy)->toHaveCount(1)
        ->and($caddy[0]['replaced'])->toBe('app-dev.caddy')
        ->and($caddy[0]['app_dev'])->toContain('cli-proxy-api.orbit')
        ->not->toContain('collector.cli-proxy-api.orbit')
        ->and($caddy[0]['collector_route'])->toBe(['status' => RouteStatus::Retiring, 'sites_published' => false])
        ->and($this->removal->events)->toBe(['dns', 'caddy', 'certificates', 'firewall'])
        ->and($this->removal->routeIds)->each->toBe($this->collector->id)
        ->and(Route::query()->whereKey($this->collector->id)->exists())->toBeFalse()
        ->and(Route::query()->whereKey($this->management->id)->exists())->toBeTrue();
});

it('restores the Route and keeps it when the Caddy reload fails', function (): void {
    $events = [];
    $manager = proxycli_takeover_manager($events, failCaddy: true);

    expect(fn () => $manager->converge($this->node, takeover: $this->collector))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('proxycli.caddy_publication_failed'));

    $route = $this->collector->refresh();

    expect(collect($events)->pluck('event')->all())
        ->toBe(['ssh:caddy-source', 'certificate:issue', 'ssh:certificate', 'ssh:caddy'])
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

    expect(collect($events)->pluck('event')->all())->not->toContain('ssh:caddy')
        ->and($this->collector->refresh()->status)->toBe(RouteStatus::Active)
        ->and($this->removal->events)->toBe([]);
});

it('leaves the Route fragment to its own publisher when no Route holds the name', function (): void {
    $events = [];
    $manager = proxycli_takeover_manager($events);
    $this->collector->delete();

    $manager->converge($this->node);

    $caddy = collect($events)->where('event', 'ssh:caddy')->values();

    expect($caddy)->toHaveCount(1)
        ->and($caddy[0]['replaced'])->toBe('')
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
        'status' => RouteStatus::Active,
    ]);
    $route->customProxy()->create(['node_id' => $node->id, 'upstream' => $upstream]);

    return $route->refresh();
}

/**
 * @param  list<array<string, mixed>>  $events
 */
function proxycli_takeover_manager(array &$events, bool $failCaddy = false): NativeProxyCliPublicationManager
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
        caddy: new ProxyCliCaddyPublisher,
        site: new ProxyCliCaddySiteRenderer,
        dns: new class($events) implements PrivateDnsManager
        {
            public function __construct(private array &$events) {}

            public function converge(?Node $pendingNode = null): void
            {
                $this->events[] = ['event' => 'dns:converge'];
            }
        },
        ssh: new class($events, $failCaddy) implements SshExecutor
        {
            public function __construct(private array &$events, private bool $failCaddy) {}

            public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
            {
                if (in_array(CaddyPackageSourceProgram::SOURCE_URI, $command->arguments, true)) {
                    $this->events[] = ['event' => 'ssh:caddy-source'];

                    return new CommandResult(0, '', '', 1, false);
                }

                if ($command->protectedInput !== null) {
                    $this->events[] = ['event' => 'ssh:certificate'];

                    return new CommandResult(0, '', '', 1, false);
                }

                $input = (string) $command->input;
                preg_match('#printf \'%s\' \'([A-Za-z0-9+/=]*)\' \| base64 --decode > "\$candidate/fragments/\$replaced_fragment"#', $input, $replacement);
                $route = Route::query()->where('domain', 'collector.cli-proxy-api.orbit')->first();
                $this->events[] = [
                    'event' => 'ssh:caddy',
                    'replaced' => $command->arguments[array_key_last($command->arguments)],
                    'app_dev' => base64_decode($replacement[1] ?? '', true),
                    'collector_route' => $route instanceof Route
                        ? ['status' => $route->status, 'sites_published' => $route->sites_published]
                        : null,
                ];

                return new CommandResult($this->failCaddy ? 1 : 0, '', '', 1, false);
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
