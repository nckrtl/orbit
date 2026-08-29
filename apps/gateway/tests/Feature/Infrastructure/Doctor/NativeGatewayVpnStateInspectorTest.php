<?php

declare(strict_types=1);

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Nodes\RoleName;
use App\Domain\Settings\SettingRepository;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AppDev\AppDevDnsConfigRenderer;
use App\Infrastructure\AppDev\AppDevSiteRepository;
use App\Infrastructure\Doctor\NativeGatewayVpnStateInspector;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Infrastructure\WireGuard\WireGuardServerConfigRenderer;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('compares the active interface and exact rendered server and DNS projections', function (): void {
    [$inspector, $ssh, $orbitHome, $role] = gateway_vpn_inspector([
        gateway_vpn_result("1\n"),
        gateway_vpn_result("1\n"),
        gateway_vpn_result("1\n"),
    ]);

    try {
        $state = $inspector->inspect($role);

        expect($state->interfaceActive)
            ->toBeTrue()
            ->and($state->serverConfigMatches)
            ->toBeTrue()
            ->and($state->dnsConfigMatches)
            ->toBeTrue()
            ->and($ssh->calls)
            ->toHaveCount(3)
            ->and($ssh->calls[0]['command']->arguments)
            ->toBe([
                'bash',
                '-ceu',
                'if systemctl is-active --quiet wg-quick@orbit; then printf \'1\\n\'; else printf \'0\\n\'; fi',
            ])
            ->and($ssh->calls[1]['command']->arguments)
            ->toContain('/etc/wireguard/orbit.conf')
            ->and($ssh->calls[2]['command']->arguments)
            ->toContain('/etc/dnsmasq.d/orbit-records.conf')
            ->and($ssh->calls[1]['protected'])
            ->toContain('PrivateKey = SERVER_PRIVATE')
            ->and($ssh->calls[2]['protected'])
            ->toStartWith('# Managed by Orbit.')
            ->and(array_column($ssh->calls, 'connection'))
            ->each(fn ($connection) => $connection->toEqual(
                new SshConnection('10.44.0.1', 'orbit', 22, '/key', '/known', commandTimeout: 30.0),
            ));

        foreach ($ssh->calls as $call) {
            expect(implode(' ', $call['command']->arguments).($call['command']->input ?? ''))
                ->not
                ->toContain('SERVER_PRIVATE', 'SERVER_PUBLIC');
        }
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('inspects the explicitly selected VPN role when a lower fleet assignment exists', function (): void {
    [$inspector, $ssh, $orbitHome] = gateway_vpn_inspector([
        gateway_vpn_result("1\n"),
        gateway_vpn_result("1\n"),
        gateway_vpn_result("1\n"),
    ]);
    $selected = Node::query()->create([
        'name' => 'selected-gateway-vpn-inspector',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.2',
        'public_ssh_port' => 2022,
        'ssh_user' => 'root',
        'wireguard_address' => '10.44.0.2',
        'wireguard_public_key' => 'SELECTED_PUBLIC',
    ]);
    $selectedRole = $selected
        ->roles()
        ->create([
            'role' => RoleName::Vpn,
            'status' => LifecycleStatus::Active,
        ]);

    try {
        $inspector->inspect($selectedRole);

        expect(array_column($ssh->calls, 'connection'))
            ->each(fn ($connection) => $connection->toEqual(
                new SshConnection('10.44.0.2', 'orbit', 22, '/key', '/known', commandTimeout: 30.0),
            ));
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('renders VPN peers in stable persisted order', function (): void {
    [$inspector, $ssh, $orbitHome, $role] = gateway_vpn_inspector([
        gateway_vpn_result("1\n"),
        gateway_vpn_result("1\n"),
        gateway_vpn_result("1\n"),
    ]);
    $firstPeer = Node::query()->create([
        'name' => 'peer-z',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.9',
        'wireguard_address' => '10.44.0.9',
        'wireguard_public_key' => 'PEER_Z_PUBLIC',
    ]);
    $secondPeer = Node::query()->create([
        'name' => 'peer-a',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.8',
        'wireguard_address' => '10.44.0.8',
        'wireguard_public_key' => 'PEER_A_PUBLIC',
    ]);

    try {
        DB::statement('PRAGMA reverse_unordered_selects = ON');
        $inspector->inspect($role);

        expect($ssh->calls[1]['protected'])
            ->toBe(implode(PHP_EOL, [
                '[Interface]',
                'Address = 10.44.0.1/24',
                'ListenPort = 51820',
                'PrivateKey = SERVER_PRIVATE',
                '',
                '[Peer]',
                "# {$firstPeer->name}",
                'PublicKey = PEER_Z_PUBLIC',
                'AllowedIPs = 10.44.0.9/32',
                '',
                '[Peer]',
                "# {$secondPeer->name}",
                'PublicKey = PEER_A_PUBLIC',
                'AllowedIPs = 10.44.0.8/32',
                '',
            ]));
    } finally {
        DB::statement('PRAGMA reverse_unordered_selects = OFF');
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('returns independent bounded mismatch observations', function (
    array $outputs,
    array $expected,
): void {
    [$inspector, $ssh, $orbitHome, $role] = gateway_vpn_inspector(array_map(
        gateway_vpn_result(...),
        $outputs,
    ));

    try {
        $state = $inspector->inspect($role);

        expect([
            $state->interfaceActive,
            $state->serverConfigMatches,
            $state->dnsConfigMatches,
        ])->toBe($expected);
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
})->with([
    'inactive interface' => [["0\n", "1\n", "1\n"], [false, true, true]],
    'server mismatch' => [["1\n", "0\n", "1\n"], [true, false, true]],
    'DNS mismatch' => [["1\n", "1\n", "0\n"], [true, true, false]],
]);

it('fails closed for command failure timeout truncation malformed output and transport errors', function (
    array $results,
    bool $throws = false,
): void {
    [$inspector, $ssh, $orbitHome, $role] = gateway_vpn_inspector($results);
    $ssh->throws = $throws;

    try {
        expect(fn () => $inspector->inspect($role))->toThrow(DoctorInspectionException::class, '');
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
})->with([
    'command failure' => [[new CommandResult(1, 'secret-output', 'secret-error', 1, false)]],
    'timeout' => [[new CommandResult(124, '', 'timeout secret', 30_000, false)]],
    'truncation' => [[new CommandResult(0, "1\n", '', 1, true)]],
    'malformed output' => [[gateway_vpn_result("1\nsecret-config\n")]],
    'transport error' => [[], true],
]);

/** @return array{NativeGatewayVpnStateInspector, GatewayVpnInspectorSsh, string, NodeRole} */
function gateway_vpn_inspector(array $results): array
{
    $orbitHome = sys_get_temp_dir().'/orbit-doctor-vpn-'.Str::uuid();
    mkdir(directory: $orbitHome.'/wireguard', permissions: 0o700, recursive: true);
    file_put_contents(filename: $orbitHome.'/wireguard/private.key', data: 'SERVER_PRIVATE');
    file_put_contents(filename: $orbitHome.'/wireguard/public.key', data: 'SERVER_PUBLIC');

    $gateway = Node::query()->create([
        'name' => 'gateway-vpn-inspector',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.1',
        'public_ssh_port' => 2022,
        'ssh_user' => 'root',
        'wireguard_address' => '10.44.0.1',
        'wireguard_public_key' => 'SERVER_PUBLIC',
    ]);
    $role = $gateway
        ->roles()
        ->create([
            'role' => RoleName::Vpn,
            'status' => LifecycleStatus::Active,
        ]);
    $settings = new VpnSettings(app(SettingRepository::class));
    $settings->configure('10.44.0.0/24');
    $ssh = new GatewayVpnInspectorSsh($results);
    $inspector = new NativeGatewayVpnStateInspector(
        $ssh,
        new GatewayVpnInspectorKeys,
        new GatewayVpnInspectorKnownHosts,
        new VpnConfigurationRepository($settings, $orbitHome),
        new WireGuardServerConfigRenderer,
        new AppDevDnsConfigRenderer(new AppDevSiteRepository),
        new CommandDeadline,
    );

    return [$inspector, $ssh, $orbitHome, $role];
}

function gateway_vpn_result(string $stdout): CommandResult
{
    return new CommandResult(0, $stdout, '', 1, false);
}

final class GatewayVpnInspectorSsh implements SshExecutor
{
    /** @var list<array{connection: SshConnection, command: RemoteCommand, protected: string}> */
    public array $calls = [];

    public bool $throws = false;

    /** @param list<CommandResult> $results */
    public function __construct(
        private array $results,
    ) {}

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $protected = '';
        if ($command->protectedInput !== null) {
            $contents = stream_get_contents($command->protectedInput->stream());
            $protected = is_string($contents) ? $contents : '';
        }
        $this->calls[] = ['connection' => $connection, 'command' => $command, 'protected' => $protected];

        if ($this->throws) {
            throw new RuntimeException('secret transport detail');
        }

        return array_shift($this->results) ?? throw new RuntimeException('Unexpected VPN inspector call.');
    }
}

/** @mago-expect lint:single-class-per-file Test-local fake keeps key material isolated. */
final readonly class GatewayVpnInspectorKeys implements SshKeyProvider
{
    public function privateKeyPath(): string
    {
        return '/key';
    }

    public function publicKey(): string
    {
        return 'public';
    }
}

/** @mago-expect lint:single-class-per-file Test-local fake keeps host state isolated. */
final readonly class GatewayVpnInspectorKnownHosts implements KnownHostsStore
{
    public function path(): string
    {
        return '/known';
    }

    public function put(string $host, int $port, \App\Infrastructure\Ssh\HostKey $key): void {}
}
