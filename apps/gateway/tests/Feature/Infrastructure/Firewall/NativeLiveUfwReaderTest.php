<?php

declare(strict_types=1);

use App\Domain\Firewall\LiveFirewallBackendStatus;
use App\Infrastructure\Firewall\NativeLiveUfwReader;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;

it('classifies every shared UFW observation outcome without mutating the node', function (
    string $platform,
    ?string $address,
    CommandResult|Throwable $response,
    LiveFirewallBackendStatus $backend,
    string $stdout,
    int $commands,
): void {
    $node = new Node(['platform' => $platform, 'wireguard_ip' => $address, 'user' => 'nckrtl']);
    $before = $node->getAttributes();
    $ssh = new UfwReaderFakeSsh($response);
    $reader = new NativeLiveUfwReader($ssh, new UfwReaderFakeKeys, new UfwReaderFakeHosts);

    expect($reader->read($node))->toBe(['backend' => $backend, 'stdout' => $stdout])
        ->and($node->getAttributes())->toBe($before)
        ->and($ssh->commands)->toHaveCount($commands);

    if ($commands === 1) {
        expect($ssh->commands[0]->arguments)->toBe(['sudo', 'ufw', 'status', 'numbered'])
            ->and($ssh->commands[0]->input)->toBeNull()
            ->and($ssh->connections[0])->toEqual(new SshConnection(
                '10.44.0.3', 'nckrtl', 22, '/key', '/known', commandTimeout: 30.0,
            ));
    }
})->with([
    'unsupported platform' => ['darwin', '10.44.0.3', new CommandResult(0, "Status: active\n", '', 1, false), LiveFirewallBackendStatus::Unreachable, '', 0],
    'missing WireGuard address' => ['linux', null, new CommandResult(0, "Status: active\n", '', 1, false), LiveFirewallBackendStatus::Unreachable, '', 0],
    'empty WireGuard address' => ['linux', '', new CommandResult(0, "Status: active\n", '', 1, false), LiveFirewallBackendStatus::Unreachable, '', 0],
    'transport exception' => ['linux', '10.44.0.3', new RuntimeException('secret transport'), LiveFirewallBackendStatus::Unreachable, '', 1],
    'nonzero exit' => ['linux', '10.44.0.3', new CommandResult(1, 'secret output', 'secret stderr', 1, false), LiveFirewallBackendStatus::Unreachable, '', 1],
    'truncated success' => ['linux', '10.44.0.3', new CommandResult(0, "Status: active\n", 'secret stderr', 1, true), LiveFirewallBackendStatus::Unreachable, '', 1],
    'malformed status' => ['linux', '10.44.0.3', new CommandResult(0, 'malformed output', 'secret stderr', 1, false), LiveFirewallBackendStatus::Unreachable, 'malformed output', 1],
    'active' => ['linux', '10.44.0.3', new CommandResult(0, "Status: active\n", '', 1, false), LiveFirewallBackendStatus::Active, "Status: active\n", 1],
    'inactive' => ['linux', '10.44.0.3', new CommandResult(0, "Status: inactive\n", '', 1, false), LiveFirewallBackendStatus::Inactive, "Status: inactive\n", 1],
    'absent' => ['linux', '10.44.0.3', new CommandResult(0, "Status: absent\n", '', 1, false), LiveFirewallBackendStatus::Absent, "Status: absent\n", 1],
]);

it('caps each fresh UFW observation at the remaining command deadline', function (): void {
    $now = 10.0;
    $deadline = new CommandDeadline(static function () use (&$now): float {
        return $now;
    });
    $deadline->start(60.0);
    $node = new Node(['platform' => 'linux', 'wireguard_ip' => '10.44.0.3', 'user' => 'nckrtl']);
    $ssh = new UfwReaderFakeSsh(new CommandResult(0, "Status: active\n", '', 1, false));
    $reader = new NativeLiveUfwReader($ssh, new UfwReaderFakeKeys, new UfwReaderFakeHosts, $deadline);

    expect($reader->read($node)['backend'])->toBe(LiveFirewallBackendStatus::Active);
    $now = 65.0;
    $ssh->response = new CommandResult(0, "Status: inactive\n", '', 1, false);
    expect($reader->read($node)['backend'])->toBe(LiveFirewallBackendStatus::Inactive)
        ->and(array_map(static fn (SshConnection $connection): float => $connection->commandTimeout, $ssh->connections))
        ->toBe([30.0, 5.0]);
    $now = 70.0;
    expect($reader->read($node))->toBe(['backend' => LiveFirewallBackendStatus::Unreachable, 'stdout' => ''])
        ->and($ssh->commands)->toHaveCount(2);
});

final class UfwReaderFakeSsh implements SshExecutor
{
    /** @var list<RemoteCommand> */
    public array $commands = [];

    /** @var list<SshConnection> */
    public array $connections = [];

    public function __construct(public CommandResult|Throwable $response) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->commands[] = $command;
        $this->connections[] = $connection;
        if ($this->response instanceof Throwable) {
            throw $this->response;
        }

        return $this->response;
    }
}

final readonly class UfwReaderFakeKeys implements SshKeyProvider
{
    public function privateKeyPath(): string
    {
        return '/key';
    }

    public function publicKey(): string
    {
        return 'key';
    }
}

final readonly class UfwReaderFakeHosts implements KnownHostsStore
{
    public function path(): string
    {
        return '/known';
    }

    public function put(string $host, int $port, HostKey $hostKey): void {}
}
