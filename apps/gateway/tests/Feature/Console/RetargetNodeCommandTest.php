<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\HostKeyScanner;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\WireGuard\WireGuardPeerConverger;
use App\Models\Node;

beforeEach(function (): void {
    app()->instance(HostKeyScanner::class, new class implements HostKeyScanner {
        public function scan(string $host, int $port): HostKey
        {
            return new HostKey('ssh-ed25519', 'host-key', 'SHA256:pinned');
        }
    });
    app()->instance(KnownHostsStore::class, new class implements KnownHostsStore {
        public function put(string $host, int $port, HostKey $key): void {}

        public function path(): string
        {
            return '/tmp/known-hosts';
        }
    });
    app()->instance(SshKeyProvider::class, new class implements SshKeyProvider {
        public function privateKeyPath(): string
        {
            return '/tmp/id_ed25519';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 AAAAC3Nza';
        }
    });
    app()->instance(SshExecutor::class, new class implements SshExecutor {
        /** @var list<SshConnection> */
        public array $connections = [];

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->connections[] = $connection;

            return new CommandResult(0, '', '', 1, false);
        }
    });
    app()->instance(WireGuardPeerConverger::class, new class implements WireGuardPeerConverger {
        public ?SshConnection $connection = null;

        public function converge(Node $node, SshConnection $connection): void
        {
            $this->connection = $connection;
        }
    });
});

it('retargets a node from the gateway console', function (): void {
    Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.10',
        'public_ssh_port' => 22,
        'user' => 'nckrtl',
        'wireguard_address' => '10.44.0.3',
        'ssh_host_fingerprint' => 'SHA256:pinned',
    ]);

    $this
        ->artisan('orbit:node-retarget', [
            'name' => 'app-dev',
            'host' => '198.51.100.25',
            '--ssh-port' => '2202',
        ])
        ->expectsOutputToContain('Node [app-dev] is active.')
        ->assertExitCode(0);

    $node = Node::query()->where('name', 'app-dev')->sole();
    /** @var WireGuardPeerConverger&object{connection:?SshConnection} $wireGuard */
    $wireGuard = app(WireGuardPeerConverger::class);
    /** @var SshExecutor&object{connections:list<SshConnection>} $ssh */
    $ssh = app(SshExecutor::class);

    expect($node->public_ssh_host)
        ->toBe('198.51.100.25')
        ->and($node->public_ssh_port)
        ->toBe(2202)
        ->and($wireGuard->connection?->host)
        ->toBe('198.51.100.25')
        ->and($wireGuard->connection?->port)
        ->toBe(2202)
        ->and($wireGuard->connection?->user)
        ->toBe('nckrtl')
        ->and($ssh->connections)
        ->toHaveCount(1)
        ->and($ssh->connections[0]->user)
        ->toBe('nckrtl');
});

it('reports typed retarget failures without leaking command output', function (): void {
    app()->instance(HostKeyScanner::class, new class implements HostKeyScanner {
        public function scan(string $host, int $port): HostKey
        {
            throw new RuntimeException('sensitive command output');
        }
    });
    Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.10',
        'ssh_host_fingerprint' => 'SHA256:pinned',
    ]);

    $this
        ->artisan('orbit:node-retarget', [
            'name' => 'app-dev',
            'host' => '198.51.100.25',
        ])
        ->expectsOutput('Node retarget failed at step [ssh-host-key] with error [node.ssh_host_key_scan_failed].')
        ->doesntExpectOutputToContain('sensitive command output')
        ->assertExitCode(1);
});

it('rejects an out of range ssh port before retargeting', function (): void {
    $this
        ->artisan('orbit:node-retarget', [
            'name' => 'app-dev',
            'host' => '198.51.100.25',
            '--ssh-port' => '65536',
        ])
        ->expectsOutput('Node retarget arguments are invalid.')
        ->assertExitCode(1);
});
