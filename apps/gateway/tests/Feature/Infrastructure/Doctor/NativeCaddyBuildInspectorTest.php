<?php

declare(strict_types=1);

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildLock;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Caddy\Build\NodeCaddyLiveReader;
use App\Infrastructure\Caddy\Build\NodeCaddyTransport;
use App\Infrastructure\Doctor\NativeCaddyBuildInspector;
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
use Tests\Support\CaddySiteCertificateFixtures;

beforeEach(function (): void {
    $this->ssh = new CaddyBuildInspectorSsh;
    $this->lockDirectory = sys_get_temp_dir().'/orbit-caddy-build-inspector-'.bin2hex(random_bytes(6));
});

afterEach(function (): void {
    new Filesystem()->deleteDirectory($this->lockDirectory);
});

it('matches a live Caddyfile that is byte for byte the fresh build', function (): void {
    $node = caddy_build_inspector_websocket_node();
    $render = app(NodeCaddyfileRenderer::class)->render($node);
    $this->ssh->live = $render->content;

    $observation = caddy_build_inspector($this)->inspect($node);

    expect($observation?->matches)->toBeTrue()
        ->and($observation?->expectedVersion)->toBe($render->version)
        ->and($observation?->liveVersion)->toBe($render->version)
        ->and($observation?->sources)->toBe(['websocket'])
        ->and($this->ssh->commands[0]->arguments)->toBe(['sudo', 'bash', '-seu', '--', '/etc/caddy/Caddyfile']);
});

it('reports a hand edit of a built Caddyfile with the digest of the live file', function (): void {
    $node = caddy_build_inspector_websocket_node();
    $render = app(NodeCaddyfileRenderer::class)->render($node);
    $this->ssh->live = $render->content."\nhand.example.test {\n    respond hi\n}\n";

    $observation = caddy_build_inspector($this)->inspect($node);

    expect($observation?->matches)->toBeFalse()
        ->and($observation?->expectedVersion)->toBe($render->version)
        ->and($observation?->liveVersion)->toBe(NodeCaddyfileRenderer::version($this->ssh->live));
});

it('reports a live Caddyfile that no build wrote as not built', function (string $live): void {
    $node = caddy_build_inspector_websocket_node();
    $this->ssh->live = $live;

    $observation = caddy_build_inspector($this)->inspect($node);

    expect($observation?->matches)->toBeFalse()
        ->and($observation?->liveVersion)->toBeNull();
})->with([
    'the fragment layout of an earlier release' => ["{\n    auto_https disable_certs\n}\nimport /etc/caddy/orbit-versions/0123456789abcdef/fragments/*.caddy\n"],
    'a foreign file' => ["hand.example.test {\n    respond hi\n}\n"],
    'no file' => [''],
]);

it('compares a Node whose Caddy role renders no site yet', function (): void {
    $node = caddy_build_inspector_node('app-dev');
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $render = app(NodeCaddyfileRenderer::class)->render($node);
    $this->ssh->live = $render->content;

    $observation = caddy_build_inspector($this)->inspect($node);

    expect($observation?->matches)->toBeTrue()
        ->and($observation?->sources)->toBe([]);
});

it('skips a Node that serves no Caddy site and holds no Caddy role, without contacting it', function (Closure $prepare): void {
    $node = $prepare(caddy_build_inspector_node('vpn'));

    expect(caddy_build_inspector($this)->inspect($node))->toBeNull()
        ->and($this->ssh->commands)->toBe([]);
})->with([
    'a Node without Caddy roles' => [static function (Node $node): Node {
        $node->roles()->create(['role' => RoleName::Vpn, 'status' => LifecycleStatus::Active]);

        return $node;
    }],
    'a Node whose Caddy role is being removed' => [static function (Node $node): Node {
        $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Removing]);

        return $node;
    }],
    'a Node that is not Linux' => [static function (Node $node): Node {
        $node->update(['platform' => 'darwin']);
        $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);

        return $node;
    }],
]);

it('fails closed when the live Caddyfile cannot be read', function (CommandResult $result): void {
    $node = caddy_build_inspector_websocket_node();
    $this->ssh->result = $result;

    expect(fn () => caddy_build_inspector($this)->inspect($node))->toThrow(DoctorInspectionException::class);
})->with([
    'a failed read' => [new CommandResult(1, '', 'sudo: a password is required', 1, false)],
    'a truncated read' => [new CommandResult(0, 'partial', '', 1, true)],
]);

it('waits for a running build of the Node and reports its read as failed when the build keeps the lock', function (): void {
    $node = caddy_build_inspector_websocket_node();
    $this->ssh->live = app(NodeCaddyfileRenderer::class)->render($node)->content;
    $now = 0.0;
    $lock = new NodeCaddyBuildLock($this->lockDirectory, clock: function () use (&$now): float {
        return $now += 31.0;
    }, wait: static function (int $microseconds): void {});
    mkdir($this->lockDirectory, 0o700, true);
    $held = fopen("{$this->lockDirectory}/node-{$node->id}.lock", 'c+');
    flock($held, LOCK_EX);

    try {
        expect(fn () => caddy_build_inspector($this, $lock)->inspect($node))->toThrow(DoctorInspectionException::class)
            ->and($this->ssh->commands)->toBe([]);
    } finally {
        flock($held, LOCK_UN);
        fclose($held);
    }

    expect(caddy_build_inspector($this, $lock)->inspect($node)?->matches)->toBeTrue();
});

function caddy_build_inspector(object $test, ?NodeCaddyBuildLock $lock = null): NativeCaddyBuildInspector
{
    return new NativeCaddyBuildInspector(
        app(NodeCaddyfileRenderer::class),
        new NodeCaddyLiveReader(new NodeCaddyTransport(
            new CaddyBuildInspectorProcesses,
            $test->ssh,
            new CaddyBuildInspectorKeys,
            new CaddyBuildInspectorKnownHosts,
            app(GatewayServingHost::class),
        )),
        $lock ?? new NodeCaddyBuildLock($test->lockDirectory),
    );
}

function caddy_build_inspector_node(string $name): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "{$name}.example.test",
        'wireguard_ip' => '10.44.0.7',
        'user' => 'orbit',
    ]);
}

function caddy_build_inspector_websocket_node(): Node
{
    $node = caddy_build_inspector_node('services');
    $node->roles()->create(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Active]);
    CaddySiteCertificateFixtures::recordAll($node);

    return $node;
}

final class CaddyBuildInspectorSsh implements SshExecutor
{
    public string $live = '';

    public ?CommandResult $result = null;

    /** @var list<RemoteCommand> */
    public array $commands = [];

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->commands[] = $command;

        return $this->result ?? new CommandResult(0, $this->live, '', 1, false);
    }
}

final class CaddyBuildInspectorProcesses implements ProcessRunner
{
    public function run(ProcessInvocation $invocation): CommandResult
    {
        throw new RuntimeException('The inspected Node does not run the Gateway.');
    }
}

final readonly class CaddyBuildInspectorKeys implements SshKeyProvider
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

final readonly class CaddyBuildInspectorKnownHosts implements KnownHostsStore
{
    public function path(): string
    {
        return '/tmp/known-hosts';
    }

    public function put(string $host, int $port, HostKey $key): void {}
}
