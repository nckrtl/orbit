<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Caddy\Build\CaddyfileSiteDiff;
use App\Infrastructure\Caddy\Build\CaddyListenerRule;
use App\Infrastructure\Caddy\Build\CaddySite;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilder;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildLock;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildResult;
use App\Infrastructure\Caddy\Build\NodeCaddyfile;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Caddy\Build\NodeCaddySiteSource;
use App\Infrastructure\Caddy\Build\NodeCaddyTransport;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->lockDirectory = sys_get_temp_dir().'/orbit-caddy-build-lock-'.bin2hex(random_bytes(6));
    $this->local = new NodeCaddyBuilderProcesses;
    $this->ssh = new NodeCaddyBuilderSsh;
});

afterEach(function (): void {
    new Filesystem()->deleteDirectory($this->lockDirectory);
});

describe('building a Node', function (): void {
    it('pushes the render over SSH to a Node that does not run the Gateway', function (): void {
        $node = node_caddy_builder_node('app-dev', '10.44.0.3');
        $this->ssh->results = [new CommandResult(0, "orbit-caddy-build-result=published\n", '', 1, false)];

        $result = node_caddy_builder($this)->build($node);

        expect($result)->toBe(NodeCaddyBuildResult::Published)
            ->and($this->ssh->connections[0]->host)->toBe('10.44.0.3')
            ->and($this->ssh->connections[0]->user)->toBe('orbit')
            ->and($this->ssh->commands[0]->arguments[0])->toBe('sudo')
            ->and($this->ssh->commands[0]->input)->toContain(base64_encode(node_caddy_builder_render($node)->content))
            ->and($this->local->invocations)->toBe([]);
    });

    it('runs the same script through local sudo on the Node that holds the gateway role', function (): void {
        $node = node_caddy_builder_node('gateway', '10.44.0.1');
        $node->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
        $this->local->results = [new CommandResult(0, "orbit-caddy-build-result=unchanged\n", '', 1, false)];

        $result = node_caddy_builder($this)->build($node);

        expect($result)->toBe(NodeCaddyBuildResult::Unchanged)
            ->and($this->local->invocations[0]->arguments[0])->toBe('sudo')
            ->and($this->ssh->commands)->toBe([]);
    });

    it('reads stored state again once it holds the Node lock', function (): void {
        $node = node_caddy_builder_node('app-dev', '10.44.0.3');
        $stale = $node->replicate();
        $stale->id = $node->id;
        $node->update(['name' => 'renamed']);

        node_caddy_builder($this)->build($stale);

        expect($this->ssh->commands[0]->input)->toContain(base64_encode(node_caddy_builder_render($node->fresh() ?? $node)->content));
    });

    it('never contacts the Node when the render has a problem', function (): void {
        $node = node_caddy_builder_node('app-dev', '10.44.0.3');

        expect(fn () => node_caddy_builder($this, duplicate: true)->build($node))
            ->toThrow(function (NodeCaddyBuildException $exception): void {
                expect($exception->details())->toBe([
                    'node' => 'app-dev',
                    'stage' => 'render',
                    'message' => 'The app-dev site one and the app-dev site two both serve shop.test:443 on 0.0.0.0.',
                ]);
            });
        expect($this->ssh->commands)->toBe([]);
    });

    it('reports the failed stage and the last Caddy message', function (): void {
        $node = node_caddy_builder_node('app-dev', '10.44.0.3');
        $this->ssh->results = [new CommandResult(
            1,
            '',
            "Error: loading certificate: open /etc/caddy/orbit-certificates/route-1/current/cert.pem: no such file\norbit-caddy-build-stage=validate\n",
            1,
            false,
        )];

        expect(fn () => node_caddy_builder($this)->build($node))
            ->toThrow(function (NodeCaddyBuildException $exception): void {
                expect($exception->stage)->toBe('validate')
                    ->and($exception->detail)->toBe('Error: loading certificate: open /etc/caddy/orbit-certificates/route-1/current/cert.pem: no such file')
                    ->and($exception->getMessage())->toStartWith('The Caddy build for Node [app-dev] failed at stage [validate]');
            });
    });

    it('reports a connection failure when the script left no stage', function (): void {
        $node = node_caddy_builder_node('app-dev', '10.44.0.3');
        $this->ssh->results = [new CommandResult(255, '', "ssh: connect to host 10.44.0.3 port 22: Connection refused\n", 1, false)];

        expect(fn () => node_caddy_builder($this)->build($node))
            ->toThrow(fn (NodeCaddyBuildException $exception) => expect($exception->stage)->toBe('connect'));
    });
});

describe('the Gateway Node lock', function (): void {
    it('fails after waiting 30 seconds while another build holds the Node', function (): void {
        $now = 0.0;
        $lock = new NodeCaddyBuildLock(
            directory: $this->lockDirectory,
            clock: function () use (&$now): float {
                return $now;
            },
            wait: function (int $microseconds) use (&$now): void {
                $now += $microseconds / 1_000_000;
            },
        );
        mkdir($this->lockDirectory, 0o700, true);
        $holder = fopen($this->lockDirectory.'/node-7.lock', 'c+');
        flock($holder, LOCK_EX);

        try {
            expect(fn () => $lock->run(7, 'app-dev', static fn (): string => 'built'))
                ->toThrow(fn (NodeCaddyBuildException $exception) => expect($exception->stage)->toBe('gateway-lock'));
            expect($now)->toBeGreaterThanOrEqual(30.0);
        } finally {
            flock($holder, LOCK_UN);
            fclose($holder);
        }

        expect($lock->run(7, 'app-dev', static fn (): string => 'built'))->toBe('built')
            ->and($lock->run(8, 'other', static fn (): string => 'other'))->toBe('other');
    });

    it('lets a nested build for the same Node reuse the held lock', function (): void {
        $lock = new NodeCaddyBuildLock($this->lockDirectory);

        expect($lock->run(7, 'app-dev', static fn (): string => $lock->run(7, 'app-dev', static fn (): string => 'nested')))->toBe('nested')
            ->and(fileperms($this->lockDirectory) & 0o777)->toBe(0o700)
            ->and(fileperms($this->lockDirectory.'/node-7.lock') & 0o777)->toBe(0o600);
    });
});

describe('the site diff', function (): void {
    it('compares two Caddyfiles site by site, ignoring comments and indentation', function (): void {
        $live = <<<'CADDY'
            {
                auto_https disable_certs
            }
            # orbit-live-fragment: websocket.caddy
            reverb.orbit {
                bind 10.44.0.1
                reverse_proxy 127.0.0.1:8080
            }
            https://shop.test {
              bind 0.0.0.0
              respond ok
            }
            hand.example.com {
                respond hi
            }
            CADDY;
        $build = <<<'CADDY'
            # Managed by Orbit: Node Caddy build
            {
                auto_https disable_certs
            }

            # orbit: app-dev route-1-router
            https://shop.test {
                bind 0.0.0.0
                respond ok
            }

            # orbit: websocket reverb.orbit
            reverb.orbit {
                bind 0.0.0.0
                reverse_proxy 127.0.0.1:8080
            }

            # orbit: gateway gateway.orbit
            gateway.orbit, 10.44.0.1 {
                handle {
                    respond gateway
                }
            }
            CADDY;

        expect(CaddyfileSiteDiff::compare($live, $build))->toBe([
            ['address' => '{ }', 'status' => 'same', 'removed' => [], 'added' => []],
            ['address' => 'https://shop.test', 'status' => 'same', 'removed' => [], 'added' => []],
            ['address' => 'reverb.orbit', 'status' => 'changed', 'removed' => ['bind 10.44.0.1'], 'added' => ['bind 0.0.0.0']],
            ['address' => 'gateway.orbit, 10.44.0.1', 'status' => 'build only', 'removed' => [], 'added' => []],
            ['address' => 'hand.example.com', 'status' => 'live only', 'removed' => [], 'added' => []],
        ]);
    });
});

describe('the dry-run command', function (): void {
    it('refuses to run without --dry-run because the build is not live yet', function (): void {
        node_caddy_builder_node('app-dev', '10.44.0.3');

        $this->artisan('orbit:caddy-build', ['node' => 'app-dev'])
            ->expectsOutputToContain('The Node Caddy build is not live yet.')
            ->assertFailed();
    });

    it('prints the render of a Node without contacting it', function (): void {
        $node = node_caddy_builder_node('app-dev', '10.44.0.3');
        $node->roles()->create(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Active]);

        $this->artisan('orbit:caddy-build', ['node' => 'app-dev', '--dry-run' => true])
            ->expectsOutputToContain(NodeCaddyfileRenderer::Marker)
            ->assertSuccessful();
    });

    it('prints each refusal and fails when the render has a problem', function (): void {
        $node = node_caddy_builder_node('app-dev', null);
        $node->roles()->create(['role' => RoleName::Analytics, 'status' => LifecycleStatus::Active]);

        $this->artisan('orbit:caddy-build', ['node' => 'app-dev', '--dry-run' => true])
            ->expectsOutputToContain('Build refused: The analytics site source needs the WireGuard IPv4 address of Node [app-dev].')
            ->assertFailed();
    });

    it('compares the render with the live file read over SSH', function (): void {
        $node = node_caddy_builder_node('app-dev', '10.44.0.3');
        $node->roles()->create(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Active]);
        $this->ssh->results = [new CommandResult(0, "{\n    auto_https disable_certs\n    metrics {\n        per_host\n    }\n}\nold.test {\n    respond old\n}\n", '', 1, false)];
        app()->instance(NodeCaddyTransport::class, node_caddy_builder_transport($this));

        $this->artisan('orbit:caddy-build', ['node' => 'app-dev', '--dry-run' => true, '--diff' => true])
            ->expectsOutputToContain('same       { }')
            ->expectsOutputToContain('build only reverb.orbit')
            ->expectsOutputToContain('live only  old.test')
            ->assertSuccessful();

        expect($this->ssh->commands[0]->arguments)->toBe(['sudo', 'bash', '-seu', '--', '/etc/caddy/Caddyfile']);
    });
});

function node_caddy_builder_node(string $name, ?string $wireguardIp): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "{$name}.example.test",
        'wireguard_ip' => $wireguardIp,
        'user' => 'orbit',
    ]);
}

function node_caddy_builder_renderer(bool $duplicate = false): NodeCaddyfileRenderer
{
    $source = new readonly class($duplicate) implements NodeCaddySiteSource
    {
        public function __construct(private bool $duplicate) {}

        public function sites(Node $node): array
        {
            $site = static fn (string $name): CaddySite => new CaddySite(
                source: 'app-dev',
                name: $name,
                listener: CaddyListenerRule::Wildcard,
                hosts: ['shop.test'],
                port: 443,
                body: "https://shop.test {\n    bind 0.0.0.0\n    respond {$node->name}\n}\n",
            );

            return $this->duplicate ? [$site('one'), $site('two')] : [$site('one')];
        }
    };

    return new NodeCaddyfileRenderer([$source]);
}

function node_caddy_builder_render(Node $node): NodeCaddyfile
{
    return node_caddy_builder_renderer()->render($node);
}

function node_caddy_builder_transport(object $test): NodeCaddyTransport
{
    return new NodeCaddyTransport($test->local, $test->ssh, new NodeCaddyBuilderKeys, new NodeCaddyBuilderKnownHosts);
}

function node_caddy_builder(object $test, bool $duplicate = false): NodeCaddyBuilder
{
    return new NodeCaddyBuilder(
        node_caddy_builder_renderer($duplicate),
        new NodeCaddyBuildLock($test->lockDirectory),
        node_caddy_builder_transport($test),
    );
}

final class NodeCaddyBuilderProcesses implements ProcessRunner
{
    /** @var list<ProcessInvocation> */
    public array $invocations = [];

    /** @var list<CommandResult> */
    public array $results = [];

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $this->invocations[] = $invocation;

        return array_shift($this->results) ?? new CommandResult(0, "orbit-caddy-build-result=published\n", '', 1, false);
    }
}

final class NodeCaddyBuilderSsh implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    /** @var list<SshConnection> */
    public array $connections = [];

    /** @var list<CommandResult> */
    public array $results = [];

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->connections[] = $connection;
        $this->commands[] = $command;

        return array_shift($this->results) ?? new CommandResult(0, "orbit-caddy-build-result=published\n", '', 1, false);
    }
}

final readonly class NodeCaddyBuilderKeys implements SshKeyProvider
{
    public function privateKeyPath(): string
    {
        return '/tmp/key';
    }

    public function publicKey(): string
    {
        return 'ssh-ed25519 key';
    }
}

final readonly class NodeCaddyBuilderKnownHosts implements KnownHostsStore
{
    public function path(): string
    {
        return '/tmp/known-hosts';
    }

    public function put(string $host, int $port, HostKey $key): void {}
}
