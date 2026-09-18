<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\AppDevDnsConfigRenderer;
use App\Infrastructure\AppDev\AppDevSiteRepository;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
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

it('publishes private DNS on the VPN node when gateway and vpn are split', function (): void {
    $vpn = dns_target_node('vpn', '10.44.0.1', RoleName::Vpn);
    dns_target_node('gateway', '10.44.0.2', RoleName::Gateway);
    $processes = new DnsTargetProcessRunner;
    $ssh = new DnsTargetSshExecutor;

    dns_target_manager($processes, $ssh)->converge();

    expect($processes->calls)
        ->toBe(0)
        ->and($ssh->hosts)
        ->toBe(['10.44.0.1'])
        ->and($ssh->users)
        ->toBe([$vpn->user])
        ->and($ssh->commands[0]->arguments)
        ->toBe(['sudo', 'bash', '-seu'])
        ->and($ssh->commands[0]->input)
        ->toContain('orbit-records.conf')
        ->toContain('catalog.json')
        ->not->toContain('orbit-private-dns.service');
});

it('does not rewrite the listener unit when publishing records to a remote vpn node', function (): void {
    dns_target_node('vpn', '10.44.0.1', RoleName::Vpn);
    dns_target_node('gateway', '10.44.0.2', RoleName::Gateway);
    $processes = new DnsTargetProcessRunner;
    $ssh = new DnsTargetSshExecutor;

    new DnsmasqPrivateDnsManager(
        processes: $processes,
        renderer: new AppDevDnsConfigRenderer(new AppDevSiteRepository),
        activateListener: true,
        checkoutPath: '/srv/orbit/gateway-new',
        ssh: $ssh,
        keys: new DnsTargetSshKeyProvider,
        knownHosts: new DnsTargetKnownHostsStore,
    )->converge();

    expect($processes->calls)
        ->toBe(0)
        ->and($ssh->hosts)
        ->toBe(['10.44.0.1'])
        ->and($ssh->commands[0]->input)
        ->toContain('orbit-records.conf')
        ->not->toContain('/srv/orbit/gateway-new')
        ->not->toContain('orbit-private-dns.service');
});

it('keeps local publication when gateway and vpn share a node', function (): void {
    $node = dns_target_node('gateway', '10.44.0.1', RoleName::Gateway);
    $node->roles()->create([
        'role' => RoleName::Vpn,
        'status' => LifecycleStatus::Active,
    ]);
    $processes = new DnsTargetProcessRunner;
    $ssh = new DnsTargetSshExecutor;

    dns_target_manager($processes, $ssh)->converge();

    expect($processes->calls)
        ->toBe(1)
        ->and($ssh->hosts)
        ->toBe([]);
});

it('fails remote publication without writing through the local process runner', function (): void {
    dns_target_node('vpn', '10.44.0.1', RoleName::Vpn);
    dns_target_node('gateway', '10.44.0.2', RoleName::Gateway);
    $processes = new DnsTargetProcessRunner;
    $ssh = new DnsTargetSshExecutor(succeed: false);

    expect(fn () => dns_target_manager($processes, $ssh)->converge())
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('app-dev.dns_config_failed')
                ->and($exception->getMessage())
                ->toContain('vpn');
        })
        ->and($processes->calls)
        ->toBe(0);
});

function dns_target_manager(ProcessRunner $processes, SshExecutor $ssh): DnsmasqPrivateDnsManager
{
    return new DnsmasqPrivateDnsManager(
        processes: $processes,
        renderer: new AppDevDnsConfigRenderer(new AppDevSiteRepository),
        ssh: $ssh,
        keys: new DnsTargetSshKeyProvider,
        knownHosts: new DnsTargetKnownHostsStore,
    );
}

function dns_target_node(string $name, string $address, RoleName $role): Node
{
    $node = Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => $name.'.example.test',
        'user' => 'orbit',
        'wireguard_ip' => $address,
    ]);
    $node->roles()->create([
        'role' => $role,
        'status' => LifecycleStatus::Active,
    ]);

    return $node;
}

final class DnsTargetProcessRunner implements ProcessRunner
{
    public int $calls = 0;

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $this->calls++;

        return new CommandResult(0, '', '', 1, false);
    }
}

final class DnsTargetSshExecutor implements SshExecutor
{
    /** @var list<string> */
    public array $hosts = [];

    /** @var list<string> */
    public array $users = [];

    /** @var list<RemoteCommand> */
    public array $commands = [];

    public function __construct(
        private bool $succeed = true,
    ) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->hosts[] = $connection->host;
        $this->users[] = $connection->user;
        $this->commands[] = $command;

        return new CommandResult($this->succeed ? 0 : 1, '', $this->succeed ? '' : 'dnsmasq failed', 1, false);
    }
}

final readonly class DnsTargetSshKeyProvider implements SshKeyProvider
{
    public function privateKeyPath(): string
    {
        return '/tmp/orbit-dns.key';
    }

    public function publicKey(): string
    {
        return 'ssh-ed25519 dns';
    }
}

final readonly class DnsTargetKnownHostsStore implements KnownHostsStore
{
    public function path(): string
    {
        return '/tmp/orbit-dns-known-hosts';
    }

    public function put(string $host, int $port, HostKey $key): void {}
}
