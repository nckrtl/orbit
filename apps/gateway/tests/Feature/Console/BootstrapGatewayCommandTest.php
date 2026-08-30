<?php

declare(strict_types=1);

use App\Actions\Gateway\BootstrapGatewayAction;
use App\Actions\Gateway\GatewayBootstrapIdentityValidator;
use App\Actions\Gateway\GatewayOperatingSystemGuard;
use App\Data\Gateway\BootstrapGatewayData;
use App\Domain\Gateway\GatewaySelfAccessConverger;
use App\Domain\Gateway\GatewayVpnConverger;
use App\Domain\Gateway\GatewayWebConverger;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\Files\ProtectedFileWriter;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
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
        assignRole: app(App\Actions\Nodes\AssignRoleAction::class),
        identity: new GatewayBootstrapIdentityValidator,
        operatingSystem: new GatewayOperatingSystemGuard($osReleasePath),
        vpnSettings: app(VpnSettings::class),
        processes: new NativeProcessRunner,
        files: new ProtectedFileWriter,
        vpn: new class($failure) implements GatewayVpnConverger {
            public function __construct(
                private NodeProvisioningException $failure,
            ) {}

            public function converge(Node $gateway, BootstrapGatewayData $data): void
            {
                throw $this->failure;
            }
        },
        web: new class implements GatewayWebConverger {
            public function converge(string $hostname, string $wireguardAddress): void {}
        },
        selfAccess: new class implements GatewaySelfAccessConverger {
            public function converge(Node $node): void {}
        },
        orbitHome: $orbitHome,
    ));

    try {
        $this
            ->artisan('orbit:bootstrap', ['public-host' => 'gateway.example.test'])
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

it('does not convert non-provisioning failures into the stable diagnostic', function (): void {
    expect(fn () => $this->artisan('orbit:bootstrap', [
        'public-host' => 'gateway.example.test',
        '--wireguard-address' => 'not-an-ip',
    ]))
        ->toThrow(InvalidArgumentException::class, 'Gateway WireGuard address [not-an-ip] is invalid.')
        ->not->toThrow('Gateway bootstrap failed at step');
});
