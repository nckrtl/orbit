<?php

declare(strict_types=1);

use App\Actions\Gateway\BootstrapGatewayAction;
use App\Actions\Gateway\GatewayBootstrapIdentityValidator;
use App\Actions\Gateway\GatewayOperatingSystemGuard;
use App\Actions\Nodes\AssignRoleAction;
use App\Data\Gateway\BootstrapGatewayData;
use App\Domain\Gateway\GatewaySelfAccessConverger;
use App\Domain\Gateway\GatewayVpnConverger;
use App\Domain\Gateway\GatewayWebConverger;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\Files\ProtectedFileWriter;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

it('reports typed gateway provisioning failures without leaking command output', function (): void {
    $orbitHome = sys_get_temp_dir().'/orbit-command-'.Str::uuid();
    $filesystem = new Filesystem;
    $filesystem->ensureDirectoryExists($orbitHome);
    $osReleasePath = $orbitHome.'/os-release';
    $filesystem->put($osReleasePath, "ID=ubuntu\nVERSION_CODENAME=resolute\n");
    $failure = new NodeProvisioningException(
        step: 'wireguard-server-install',
        errorCode: 'vpn.server_config_install_failed',
        message: 'sensitive command output',
        result: new CommandResult(1, 'sensitive stdout', 'sensitive stderr', 42, false),
    );

    app()->instance(BootstrapGatewayAction::class, new BootstrapGatewayAction(
        assignRole: app(AssignRoleAction::class),
        identity: new GatewayBootstrapIdentityValidator,
        operatingSystem: new GatewayOperatingSystemGuard($osReleasePath),
        vpnSettings: app(VpnSettings::class),
        processes: new NativeProcessRunner,
        files: new ProtectedFileWriter,
        vpn: new class($failure) implements GatewayVpnConverger
        {
            public function __construct(
                private NodeProvisioningException $failure,
            ) {}

            public function converge(Node $gateway, BootstrapGatewayData $data): void
            {
                throw $this->failure;
            }
        },
        web: new class implements GatewayWebConverger
        {
            public function converge(string $hostname, string $wireguardIp): void {}
        },
        selfAccess: new class implements GatewaySelfAccessConverger
        {
            public function converge(Node $node): string
            {
                return 'SHA256:gateway';
            }
        },
        orbitHome: $orbitHome,
    ));

    try {
        $this
            ->artisan('orbit:bootstrap', [
                'public-host' => 'gateway.example.test',
                '--wireguard-ip' => '10.44.0.1',
                '--wireguard-address' => '10.44.0.1',
            ])
            ->expectsOutput(
                'Gateway bootstrap failed at step [wireguard-server-install] with error [vpn.server_config_install_failed].',
            )
            ->doesntExpectOutputToContain('sensitive command output')
            ->doesntExpectOutputToContain('sensitive stdout')
            ->doesntExpectOutputToContain('sensitive stderr')
            ->assertExitCode(1);
    } finally {
        $filesystem->deleteDirectory($orbitHome);
    }
});

it('rejects conflicting bootstrap WireGuard options before mutation', function (): void {
    $this
        ->artisan('orbit:bootstrap', [
            'public-host' => 'gateway.example.test',
            '--wireguard-ip' => '10.44.0.1',
            '--wireguard-address' => '10.44.0.2',
        ])
        ->expectsOutput('The WireGuard IP options conflict.')
        ->assertExitCode(1);

    expect(Node::query()->exists())->toBeFalse();
});

it('persists and resolves an implicit endpoint with the public host bytes and IPv6 brackets', function (
    string $publicHost,
    string $endpoint,
): void {
    $orbitHome = sys_get_temp_dir().'/orbit-command-'.Str::uuid();
    $filesystem = new Filesystem;
    $filesystem->ensureDirectoryExists($orbitHome);
    $osReleasePath = $orbitHome.'/os-release';
    $filesystem->put($osReleasePath, "ID=ubuntu\nVERSION_CODENAME=resolute\n");

    app()->instance(BootstrapGatewayAction::class, new BootstrapGatewayAction(
        assignRole: app(AssignRoleAction::class),
        identity: new GatewayBootstrapIdentityValidator,
        operatingSystem: new GatewayOperatingSystemGuard($osReleasePath),
        vpnSettings: app(VpnSettings::class),
        processes: new NativeProcessRunner,
        files: new ProtectedFileWriter,
        vpn: new class implements GatewayVpnConverger
        {
            public function converge(Node $gateway, BootstrapGatewayData $data): void {}
        },
        web: new class implements GatewayWebConverger
        {
            public function converge(string $hostname, string $wireguardIp): void {}
        },
        selfAccess: new class implements GatewaySelfAccessConverger
        {
            public function converge(Node $node): string
            {
                return 'SHA256:gateway';
            }
        },
        orbitHome: $orbitHome,
    ));

    try {
        $this
            ->artisan('orbit:bootstrap', [
                'public-host' => $publicHost,
                '--wireguard-ip' => '10.44.0.1',
            ])
            ->expectsOutput('Gateway [gateway] initialized.')
            ->assertSuccessful();

        $peer = Node::query()->create([
            'name' => 'app-dev',
            'public_ssh_host' => '192.0.2.20',
            'wireguard_ip' => '10.44.0.2',
            'wireguard_public_key' => 'PEER_PUBLIC',
        ]);
        $vpn = new VpnConfigurationRepository(app(VpnSettings::class), $orbitHome);

        expect(app(VpnSettings::class)->endpoint())
            ->toBe($endpoint)
            ->and($vpn->forPeer($peer)->endpoint)
            ->toBe($endpoint);
    } finally {
        $filesystem->deleteDirectory($orbitHome);
    }
})->with([
    'IPv4 bytes' => ['192.0.2.10', '192.0.2.10:51820'],
    'hostname bytes' => ['Vpn.Example.test', 'Vpn.Example.test:51820'],
    'IPv6 brackets' => ['2001:db8::10', '[2001:db8::10]:51820'],
]);

it('does not convert non-provisioning failures into the stable diagnostic', function (): void {
    expect(fn () => $this->artisan('orbit:bootstrap', [
        'public-host' => 'gateway.example.test',
        '--wireguard-address' => 'not-an-ip',
    ]))
        ->toThrow(InvalidArgumentException::class, 'Gateway WireGuard address [not-an-ip] is invalid.')
        ->not->toThrow('Gateway bootstrap failed at step');
});
