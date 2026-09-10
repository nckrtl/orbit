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
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\Files\ProtectedFileWriter;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

it('initializes the portable gateway authority idempotently', function (): void {
    $orbitHome = sys_get_temp_dir().'/orbit-bootstrap-'.(string) Str::uuid();
    $web = new class implements GatewayWebConverger
    {
        /** @var list<array{hostname: string, address: string}> */
        public array $calls = [];

        public function converge(string $hostname, string $wireguardIp): void
        {
            $this->calls[] = ['hostname' => $hostname, 'address' => $wireguardIp];
        }
    };
    $selfAccess = new class implements GatewaySelfAccessConverger
    {
        /** @var list<string> */
        public array $calls = [];

        public function converge(Node $node): string
        {
            $this->calls[] = $node->name;

            return 'SHA256:gateway';
        }
    };
    $action = new BootstrapGatewayAction(
        assignRole: app(AssignRoleAction::class),
        identity: new GatewayBootstrapIdentityValidator,
        operatingSystem: bootstrap_gateway_resolute_guard(),
        vpnSettings: app(VpnSettings::class),
        processes: new NativeProcessRunner,
        files: new ProtectedFileWriter,
        vpn: gateway_vpn_noop(),
        web: $web,
        selfAccess: $selfAccess,
        orbitHome: $orbitHome,
    );
    $data = new BootstrapGatewayData(
        publicHost: '85.9.218.89',
        wireguardIp: '10.44.0.1',
        wireguardSubnet: '10.44.0.0/24',
        wireguardEndpoint: '85.9.218.89:51820',
        dnsServer: '10.44.0.1',
        domain: 'test',
        privateInterface: 'eth3',
    );

    try {
        $first = $action->execute($data);
        $second = $action->execute($data);
        $rootCertificate = file_get_contents($orbitHome.'/ca/root.pem');
        $rootPublicKey = is_string($rootCertificate)
            ? openssl_pkey_get_public($rootCertificate)
            : false;
        $rootPublicKeyDetails = $rootPublicKey !== false
            ? openssl_pkey_get_details($rootPublicKey)
            : false;

        expect($first->is($second))
            ->toBeTrue()
            ->and($first->roles()->pluck('role')->all())
            ->toContain(RoleName::Gateway, RoleName::Vpn)
            ->and(is_file($orbitHome.'/ssh/id_ed25519'))
            ->toBeTrue()
            ->and(is_file($orbitHome.'/wireguard/private.key'))
            ->toBeTrue()
            ->and(is_file($orbitHome.'/ca/root.key'))
            ->toBeTrue()
            ->and(is_file($orbitHome.'/ca/root.pem'))
            ->toBeTrue()
            ->and(fileperms($orbitHome.'/ca/root.key') & 0o777)
            ->toBe(0o600)
            ->and(fileperms($orbitHome.'/ca/root.pem') & 0o777)
            ->toBe(0o644)
            ->and(fileperms($orbitHome.'/ca/root.lock') & 0o777)
            ->toBe(0o600)
            ->and(gateway_root_ca_pair_matches($orbitHome))
            ->toBeTrue()
            ->and($rootPublicKeyDetails['type'] ?? null)
            ->toBe(OPENSSL_KEYTYPE_RSA)
            ->and($rootPublicKeyDetails['bits'] ?? null)
            ->toBe(4096)
            ->and(gateway_root_ca_validity_days($orbitHome))
            ->toBeIn([3649, 3650])
            ->and(app(VpnSettings::class)->privateInterface())
            ->toBe('eth3')
            ->and($web->calls)
            ->toBe([
                ['hostname' => 'gateway.test', 'address' => '10.44.0.1'],
                ['hostname' => 'gateway.test', 'address' => '10.44.0.1'],
            ])
            ->and($selfAccess->calls)
            ->toBe(['gateway', 'gateway'])
            ->and($second->ssh_host_fingerprint)
            ->toBe('SHA256:gateway')
            ->and(Node::query()->count())
            ->toBe(1);
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('activates only bootstrap roles while preserving colocated role outcomes', function (): void {
    $orbitHome = sys_get_temp_dir().'/orbit-bootstrap-'.(string) Str::uuid();
    $node = bootstrap_gateway_existing_node();
    $node->roles()->create([
        'role' => RoleName::Ingress,
        'status' => LifecycleStatus::Active,
        'cluster_id' => $node->cluster_id,
    ]);
    $node->roles()->create([
        'role' => RoleName::Metrics,
        'status' => LifecycleStatus::Failed,
        'failed_step' => 'converge:metrics-runtime',
        'error_code' => 'metrics.runtime_failed',
    ]);
    $baselines = new class implements RoleBaselineConverger
    {
        /** @var list<RoleName> */
        public array $convergedRoles = [];

        public function converge(Node $node, NodeRole $assignment): void
        {
            $this->convergedRoles[] = $assignment->role;
        }

        public function remove(Node $node, NodeRole $assignment, bool $purgeData): void {}

        public function removeUnreachable(Node $node, NodeRole $assignment): void {}
    };
    app()->instance(RoleBaselineConverger::class, $baselines);
    $action = bootstrap_gateway_action($orbitHome);

    try {
        $active = $action->execute(bootstrap_gateway_action_data());

        expect($active->status)
            ->toBe(LifecycleStatus::Active)
            ->and($active->getAttribute('failed_step'))
            ->toBeNull()
            ->and($active->getAttribute('error_code'))
            ->toBeNull()
            ->and(bootstrap_gateway_role_states($active))
            ->toBe([
                'gateway' => [
                    'status' => LifecycleStatus::Active,
                    'failed_step' => null,
                    'error_code' => null,
                ],
                'ingress' => [
                    'status' => LifecycleStatus::Active,
                    'failed_step' => null,
                    'error_code' => null,
                ],
                'metrics' => [
                    'status' => LifecycleStatus::Failed,
                    'failed_step' => 'converge:metrics-runtime',
                    'error_code' => 'metrics.runtime_failed',
                ],
                'vpn' => [
                    'status' => LifecycleStatus::Active,
                    'failed_step' => null,
                    'error_code' => null,
                ],
            ])
            ->and($baselines->convergedRoles)
            ->toBeEmpty();
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('fails closed without mutating a partial root CA containing only :filename', function (
    string $filename,
    string $contents,
): void {
    $orbitHome = sys_get_temp_dir().'/orbit-bootstrap-'.(string) Str::uuid();
    mkdir(directory: $orbitHome.'/ssh', permissions: 0o700, recursive: true);
    mkdir(directory: $orbitHome.'/wireguard', permissions: 0o700, recursive: true);
    mkdir(directory: $orbitHome.'/ca', permissions: 0o700, recursive: true);
    file_put_contents($orbitHome.'/ssh/id_ed25519', data: 'private');
    file_put_contents($orbitHome.'/ssh/id_ed25519.pub', data: 'public');
    file_put_contents($orbitHome.'/wireguard/private.key', data: 'private');
    file_put_contents($orbitHome.'/wireguard/public.key', data: 'public');
    $partialPath = $orbitHome.'/ca/'.$filename;
    file_put_contents($partialPath, $contents);
    chmod(filename: $partialPath, permissions: 0o640);
    mkdir(directory: $orbitHome.'/ca/.root-ca.candidate', permissions: 0o700);
    file_put_contents($orbitHome.'/ca/.root-ca.candidate/root.key', data: 'stale-candidate');
    $processes = new class implements ProcessRunner
    {
        /** @var list<ProcessInvocation> */
        public array $invocations = [];

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->invocations[] = $invocation;

            return new CommandResult(1, '', 'unexpected process invocation', 1, false);
        }
    };
    $action = new BootstrapGatewayAction(
        assignRole: app(AssignRoleAction::class),
        identity: new GatewayBootstrapIdentityValidator,
        operatingSystem: bootstrap_gateway_resolute_guard(),
        vpnSettings: app(VpnSettings::class),
        processes: $processes,
        files: new ProtectedFileWriter,
        vpn: gateway_vpn_noop(),
        web: new class implements GatewayWebConverger
        {
            public function converge(string $hostname, string $wireguardIp): void
            {
                throw new LogicException('Web convergence must not run after CA generation fails.');
            }
        },
        selfAccess: gateway_self_access_noop(),
        orbitHome: $orbitHome,
    );

    try {
        try {
            $action->execute(bootstrap_gateway_action_data());

            throw new LogicException('A partial root CA must fail closed.');
        } catch (NodeProvisioningException $exception) {
            expect($exception->step)
                ->toBe('ca-root-validate')
                ->and($exception->errorCode)
                ->toBe('ca.invalid_state')
                ->and($exception->getMessage())
                ->toContain('restore');
        }

        $missingFilename = $filename === 'root.key' ? 'root.pem' : 'root.key';

        expect(file_get_contents($partialPath))
            ->toBe($contents)
            ->and(fileperms($partialPath) & 0o777)
            ->toBe(0o640)
            ->and(is_file($orbitHome.'/ca/'.$missingFilename))
            ->toBeFalse()
            ->and(glob($orbitHome.'/ca/*.quarantine.*'))
            ->toBeEmpty()
            ->and(file_get_contents($orbitHome.'/ca/.root-ca.candidate/root.key'))
            ->toBe('stale-candidate')
            ->and($processes->invocations)
            ->toBeEmpty();
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
})->with([
    'private key' => ['root.key', 'existing-private-key'],
    'certificate' => ['root.pem', "-----BEGIN CERTIFICATE-----\nexisting-certificate\n-----END CERTIFICATE-----\n"],
]);

it('rejects a mismatched complete root CA pair without replacing it', function (): void {
    $orbitHome = sys_get_temp_dir().'/orbit-bootstrap-'.(string) Str::uuid();
    $ca = $orbitHome.'/ca';
    mkdir(directory: $ca, permissions: 0o700, recursive: true);
    $processes = new NativeProcessRunner;
    $processes->run(new ProcessInvocation([
        'openssl',
        'genrsa',
        '-out',
        $ca.'/root.key',
        '4096',
    ]));
    $processes->run(new ProcessInvocation([
        'openssl',
        'genrsa',
        '-out',
        $ca.'/other.key',
        '4096',
    ]));
    $processes->run(new ProcessInvocation([
        'openssl',
        'req',
        '-x509',
        '-new',
        '-key',
        $ca.'/other.key',
        '-out',
        $ca.'/root.pem',
        '-days',
        '3650',
        '-subj',
        '/CN=Orbit Mismatched Root CA',
    ]));
    $originalKey = file_get_contents($ca.'/root.key');
    $originalCertificate = file_get_contents($ca.'/root.pem');
    $action = new BootstrapGatewayAction(
        assignRole: app(AssignRoleAction::class),
        identity: new GatewayBootstrapIdentityValidator,
        operatingSystem: bootstrap_gateway_resolute_guard(),
        vpnSettings: app(VpnSettings::class),
        processes: $processes,
        files: new ProtectedFileWriter,
        vpn: gateway_vpn_noop(),
        web: new class implements GatewayWebConverger
        {
            public function converge(string $hostname, string $wireguardIp): void
            {
                throw new LogicException('Web convergence must not run for an invalid CA pair.');
            }
        },
        selfAccess: gateway_self_access_noop(),
        orbitHome: $orbitHome,
    );

    try {
        try {
            $action->execute(bootstrap_gateway_action_data());

            throw new LogicException('A mismatched root CA pair must fail closed.');
        } catch (NodeProvisioningException $exception) {
            expect($exception->step)
                ->toBe('ca-root-validate')
                ->and($exception->errorCode)
                ->toBe('ca.invalid_state');
        }

        expect(file_get_contents($ca.'/root.key'))
            ->toBe($originalKey)
            ->and(file_get_contents($ca.'/root.pem'))
            ->toBe($originalCertificate);
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('rejects an existing root CA that is not RSA 4096', function (): void {
    $orbitHome = sys_get_temp_dir().'/orbit-bootstrap-'.Str::uuid();
    $ca = $orbitHome.'/ca';
    mkdir(directory: $ca, permissions: 0o700, recursive: true);
    $processes = new NativeProcessRunner;
    $processes->run(new ProcessInvocation([
        'openssl',
        'genrsa',
        '-out',
        $ca.'/root.key',
        '2048',
    ]));
    $processes->run(new ProcessInvocation([
        'openssl',
        'req',
        '-x509',
        '-new',
        '-key',
        $ca.'/root.key',
        '-out',
        $ca.'/root.pem',
        '-days',
        '3650',
        '-subj',
        '/CN=Orbit Root CA',
        '-addext',
        'basicConstraints=critical,CA:TRUE',
        '-addext',
        'keyUsage=critical,keyCertSign,cRLSign',
    ]));
    $action = new BootstrapGatewayAction(
        assignRole: app(AssignRoleAction::class),
        identity: new GatewayBootstrapIdentityValidator,
        operatingSystem: bootstrap_gateway_resolute_guard(),
        vpnSettings: app(VpnSettings::class),
        processes: $processes,
        files: new ProtectedFileWriter,
        vpn: gateway_vpn_noop(),
        web: new class implements GatewayWebConverger
        {
            public function converge(string $hostname, string $wireguardIp): void {}
        },
        selfAccess: gateway_self_access_noop(),
        orbitHome: $orbitHome,
    );

    try {
        expect(fn () => $action->execute(bootstrap_gateway_action_data()))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)
                    ->toBe('ca-root-validate')
                    ->and($exception->errorCode)
                    ->toBe('ca.invalid_state');
            });
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('rejects an invalid static identity before persistence or host side effects', function (
    string $wireguardIp,
    string $wireguardSubnet,
    string $domain,
    string $wireguardEndpoint,
): void {
    $orbitHome = sys_get_temp_dir().'/orbit-bootstrap-'.(string) Str::uuid();
    $processes = new class implements ProcessRunner
    {
        public int $calls = 0;

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->calls++;

            return new CommandResult(0, '', '', 1, false);
        }
    };
    $action = new BootstrapGatewayAction(
        assignRole: app(AssignRoleAction::class),
        identity: new GatewayBootstrapIdentityValidator,
        operatingSystem: bootstrap_gateway_resolute_guard(),
        vpnSettings: app(VpnSettings::class),
        processes: $processes,
        files: new ProtectedFileWriter,
        vpn: gateway_vpn_noop(),
        web: new class implements GatewayWebConverger
        {
            public function converge(string $hostname, string $wireguardIp): void
            {
                throw new LogicException('Web convergence must not run.');
            }
        },
        selfAccess: gateway_self_access_noop(),
        orbitHome: $orbitHome,
    );

    expect(fn () => $action->execute(new BootstrapGatewayData(
        publicHost: '85.9.218.89',
        wireguardIp: $wireguardIp,
        wireguardSubnet: $wireguardSubnet,
        wireguardEndpoint: $wireguardEndpoint,
        dnsServer: '10.44.0.1',
        domain: $domain,
    )))
        ->toThrow(InvalidArgumentException::class)
        ->and(Node::query()->count())
        ->toBe(0)
        ->and(is_dir($orbitHome))
        ->toBeFalse()
        ->and($processes->calls)
        ->toBe(0);
})->with([
    'invalid domain' => ['10.44.0.1', '10.44.0.0/24', 'invalid domain', '85.9.218.89:51820'],
    'subnet host bits' => ['10.44.0.2', '10.44.0.1/24', 'orbit', '85.9.218.89:51820'],
    'bad prefix' => ['10.44.0.1', '10.44.0.0/31', 'orbit', '85.9.218.89:51820'],
    'network address' => ['10.44.0.0', '10.44.0.0/30', 'orbit', '85.9.218.89:51820'],
    'broadcast address' => ['10.44.0.3', '10.44.0.0/30', 'orbit', '85.9.218.89:51820'],
    'outside address' => ['10.44.0.4', '10.44.0.0/30', 'orbit', '85.9.218.89:51820'],
    'bare IPv6 endpoint' => ['10.44.0.1', '10.44.0.0/24', 'orbit', '2001:db8::10:51820'],
    'invalid endpoint port' => ['10.44.0.1', '10.44.0.0/24', 'orbit', '85.9.218.89:0'],
    'endpoint whitespace' => ['10.44.0.1', '10.44.0.0/24', 'orbit', 'vpn.example.test :51820'],
    'endpoint control character' => [
        '10.44.0.1',
        '10.44.0.0/24',
        'orbit',
        "85.9.218.89:51820\nPostUp = touch /tmp/orbit-injected",
    ],
]);

it('accepts the shared WireGuard endpoint forms at the bootstrap boundary', function (string $endpoint): void {
    $data = bootstrap_gateway_action_data();

    expect(fn () => new GatewayBootstrapIdentityValidator()->validate(new BootstrapGatewayData(
        publicHost: $data->publicHost,
        wireguardIp: $data->wireguardIp,
        wireguardSubnet: $data->wireguardSubnet,
        wireguardEndpoint: $endpoint,
        dnsServer: $data->dnsServer,
        domain: $data->domain,
    )))
        ->not
        ->toThrow(InvalidArgumentException::class);
})->with([
    'IPv4' => '192.0.2.10:51820',
    'hostname' => 'vpn.example.test:51820',
    'bracketed IPv6' => '[2001:db8::10]:51820',
]);

it('records provisioning and failed host convergence state and activates an idempotent retry', function (): void {
    $orbitHome = sys_get_temp_dir().'/orbit-bootstrap-'.(string) Str::uuid();
    $node = bootstrap_gateway_existing_node();
    $node->roles()->create([
        'role' => RoleName::Ingress,
        'status' => LifecycleStatus::Active,
        'cluster_id' => $node->cluster_id,
    ]);
    $node->roles()->create([
        'role' => RoleName::Metrics,
        'status' => LifecycleStatus::Failed,
        'failed_step' => 'converge:metrics-runtime',
        'error_code' => 'metrics.runtime_failed',
    ]);
    $web = new class implements GatewayWebConverger
    {
        public bool $shouldFail = true;

        /** @var list<array{node: LifecycleStatus, roles: array<string, array{status: LifecycleStatus, failed_step: ?string, error_code: ?string}>}> */
        public array $observedStates = [];

        public function converge(string $hostname, string $wireguardIp): void
        {
            $node = Node::query()->where('name', 'gateway')->firstOrFail();
            $this->observedStates[] = [
                'node' => $node->status,
                'roles' => bootstrap_gateway_role_states($node),
            ];

            if ($this->shouldFail) {
                throw new NodeProvisioningException(
                    step: 'gateway-caddy-validate',
                    errorCode: 'gateway.caddy_config_invalid',
                    message: 'Simulated aggregate candidate failure.',
                );
            }
        }
    };
    $action = new BootstrapGatewayAction(
        assignRole: app(AssignRoleAction::class),
        identity: new GatewayBootstrapIdentityValidator,
        operatingSystem: bootstrap_gateway_resolute_guard(),
        vpnSettings: app(VpnSettings::class),
        processes: new NativeProcessRunner,
        files: new ProtectedFileWriter,
        vpn: gateway_vpn_noop(),
        web: $web,
        selfAccess: gateway_self_access_noop(),
        orbitHome: $orbitHome,
    );
    $data = new BootstrapGatewayData(
        publicHost: '85.9.218.89',
        wireguardIp: '10.44.0.1',
        wireguardSubnet: '10.44.0.0/24',
        wireguardEndpoint: '85.9.218.89:51820',
        dnsServer: '10.44.0.1',
        domain: 'orbit',
    );

    try {
        expect(fn () => $action->execute($data))
            ->toThrow(NodeProvisioningException::class);

        $failed = Node::query()->where('name', 'gateway')->firstOrFail();

        expect($web->observedStates[0])
            ->toBe([
                'node' => LifecycleStatus::Provisioning,
                'roles' => [
                    'gateway' => [
                        'status' => LifecycleStatus::Provisioning,
                        'failed_step' => null,
                        'error_code' => null,
                    ],
                    'ingress' => [
                        'status' => LifecycleStatus::Active,
                        'failed_step' => null,
                        'error_code' => null,
                    ],
                    'metrics' => [
                        'status' => LifecycleStatus::Failed,
                        'failed_step' => 'converge:metrics-runtime',
                        'error_code' => 'metrics.runtime_failed',
                    ],
                    'vpn' => [
                        'status' => LifecycleStatus::Provisioning,
                        'failed_step' => null,
                        'error_code' => null,
                    ],
                ],
            ])
            ->and($failed->status)
            ->toBe(LifecycleStatus::Failed)
            ->and($failed->getAttribute('failed_step'))
            ->toBe('gateway-caddy-validate')
            ->and($failed->getAttribute('error_code'))
            ->toBe('gateway.caddy_config_invalid')
            ->and(bootstrap_gateway_role_states($failed))
            ->toBe([
                'gateway' => [
                    'status' => LifecycleStatus::Failed,
                    'failed_step' => 'gateway-caddy-validate',
                    'error_code' => 'gateway.caddy_config_invalid',
                ],
                'ingress' => [
                    'status' => LifecycleStatus::Active,
                    'failed_step' => null,
                    'error_code' => null,
                ],
                'metrics' => [
                    'status' => LifecycleStatus::Failed,
                    'failed_step' => 'converge:metrics-runtime',
                    'error_code' => 'metrics.runtime_failed',
                ],
                'vpn' => [
                    'status' => LifecycleStatus::Failed,
                    'failed_step' => 'gateway-caddy-validate',
                    'error_code' => 'gateway.caddy_config_invalid',
                ],
            ]);

        $web->shouldFail = false;
        $active = $action->execute($data);

        expect($active->is($failed))
            ->toBeTrue()
            ->and($active->status)
            ->toBe(LifecycleStatus::Active)
            ->and($active->getAttribute('failed_step'))
            ->toBeNull()
            ->and($active->getAttribute('error_code'))
            ->toBeNull()
            ->and(bootstrap_gateway_role_states($active))
            ->toBe([
                'gateway' => [
                    'status' => LifecycleStatus::Active,
                    'failed_step' => null,
                    'error_code' => null,
                ],
                'ingress' => [
                    'status' => LifecycleStatus::Active,
                    'failed_step' => null,
                    'error_code' => null,
                ],
                'metrics' => [
                    'status' => LifecycleStatus::Failed,
                    'failed_step' => 'converge:metrics-runtime',
                    'error_code' => 'metrics.runtime_failed',
                ],
                'vpn' => [
                    'status' => LifecycleStatus::Active,
                    'failed_step' => null,
                    'error_code' => null,
                ],
            ])
            ->and(Node::query()->count())
            ->toBe(1);
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('records stable gateway failure state when bootstrap throws an unexpected exception', function (): void {
    $orbitHome = sys_get_temp_dir().'/orbit-bootstrap-'.(string) Str::uuid();
    $node = bootstrap_gateway_existing_node();
    $node->roles()->create([
        'role' => RoleName::Metrics,
        'status' => LifecycleStatus::Failed,
        'failed_step' => 'converge:metrics-runtime',
        'error_code' => 'metrics.runtime_failed',
    ]);
    $action = new BootstrapGatewayAction(
        assignRole: app(AssignRoleAction::class),
        identity: new GatewayBootstrapIdentityValidator,
        operatingSystem: bootstrap_gateway_resolute_guard(),
        vpnSettings: app(VpnSettings::class),
        processes: new NativeProcessRunner,
        files: new ProtectedFileWriter,
        vpn: gateway_vpn_noop(),
        web: new class implements GatewayWebConverger
        {
            public function converge(string $hostname, string $wireguardIp): void
            {
                throw new RuntimeException('Unexpected gateway web failure.');
            }
        },
        selfAccess: gateway_self_access_noop(),
        orbitHome: $orbitHome,
    );
    $data = new BootstrapGatewayData(
        publicHost: '85.9.218.89',
        wireguardIp: '10.44.0.1',
        wireguardSubnet: '10.44.0.0/24',
        wireguardEndpoint: '85.9.218.89:51820',
        dnsServer: '10.44.0.1',
        domain: 'orbit',
    );

    try {
        try {
            $action->execute($data);

            throw new LogicException('Unexpected bootstrap exceptions must be wrapped.');
        } catch (NodeProvisioningException $exception) {
            expect($exception->step)
                ->toBe('unknown')
                ->and($exception->errorCode)
                ->toBe('gateway.bootstrap_failed')
                ->and($exception->getPrevious())
                ->toBeInstanceOf(RuntimeException::class);
        }

        $failed = Node::query()->where('name', 'gateway')->firstOrFail();

        expect($failed->status)
            ->toBe(LifecycleStatus::Failed)
            ->and($failed->getAttribute('failed_step'))
            ->toBe('unknown')
            ->and($failed->getAttribute('error_code'))
            ->toBe('gateway.bootstrap_failed')
            ->and(bootstrap_gateway_role_states($failed))
            ->toBe([
                'gateway' => [
                    'status' => LifecycleStatus::Failed,
                    'failed_step' => 'unknown',
                    'error_code' => 'gateway.bootstrap_failed',
                ],
                'metrics' => [
                    'status' => LifecycleStatus::Failed,
                    'failed_step' => 'converge:metrics-runtime',
                    'error_code' => 'metrics.runtime_failed',
                ],
                'vpn' => [
                    'status' => LifecycleStatus::Failed,
                    'failed_step' => 'unknown',
                    'error_code' => 'gateway.bootstrap_failed',
                ],
            ]);
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('fails only bootstrap roles when VPN convergence fails', function (): void {
    $orbitHome = sys_get_temp_dir().'/orbit-bootstrap-'.(string) Str::uuid();
    $node = bootstrap_gateway_existing_node();
    $node->roles()->create([
        'role' => RoleName::Ingress,
        'status' => LifecycleStatus::Active,
        'cluster_id' => $node->cluster_id,
    ]);
    $node->roles()->create([
        'role' => RoleName::Metrics,
        'status' => LifecycleStatus::Failed,
        'failed_step' => 'converge:metrics-runtime',
        'error_code' => 'metrics.runtime_failed',
    ]);
    $vpn = new class implements GatewayVpnConverger
    {
        public int $calls = 0;

        public function converge(Node $gateway, BootstrapGatewayData $data): void
        {
            $this->calls++;

            throw new NodeProvisioningException(
                step: 'wireguard-config',
                errorCode: 'vpn.configuration_failed',
                message: 'Simulated VPN convergence failure.',
            );
        }
    };
    $web = new class implements GatewayWebConverger
    {
        public int $calls = 0;

        public function converge(string $hostname, string $wireguardIp): void
        {
            $this->calls++;
        }
    };
    $action = bootstrap_gateway_action($orbitHome, vpn: $vpn, web: $web);

    try {
        expect(fn () => $action->execute(bootstrap_gateway_action_data()))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)
                    ->toBe('wireguard-config')
                    ->and($exception->errorCode)
                    ->toBe('vpn.configuration_failed');
            });

        $failed = $node->refresh();

        expect($failed->status)
            ->toBe(LifecycleStatus::Failed)
            ->and($failed->getAttribute('failed_step'))
            ->toBe('wireguard-config')
            ->and($failed->getAttribute('error_code'))
            ->toBe('vpn.configuration_failed')
            ->and(bootstrap_gateway_role_states($failed))
            ->toBe([
                'gateway' => [
                    'status' => LifecycleStatus::Failed,
                    'failed_step' => 'wireguard-config',
                    'error_code' => 'vpn.configuration_failed',
                ],
                'ingress' => [
                    'status' => LifecycleStatus::Active,
                    'failed_step' => null,
                    'error_code' => null,
                ],
                'metrics' => [
                    'status' => LifecycleStatus::Failed,
                    'failed_step' => 'converge:metrics-runtime',
                    'error_code' => 'metrics.runtime_failed',
                ],
                'vpn' => [
                    'status' => LifecycleStatus::Failed,
                    'failed_step' => 'wireguard-config',
                    'error_code' => 'vpn.configuration_failed',
                ],
            ])
            ->and($vpn->calls)
            ->toBe(1)
            ->and($web->calls)
            ->toBe(0);
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('fails the assigned bootstrap role when the second role assignment fails', function (): void {
    $orbitHome = sys_get_temp_dir().'/orbit-bootstrap-'.(string) Str::uuid();
    $vpnOwner = bootstrap_gateway_existing_node(
        name: 'vpn-owner',
        publicHost: '192.0.2.45',
        wireguardIp: '10.44.0.45',
    );
    $vpnOwner
        ->roles()
        ->create([
            'role' => RoleName::Vpn,
            'status' => LifecycleStatus::Active,
        ]);
    $node = bootstrap_gateway_existing_node();
    $node->roles()->create([
        'role' => RoleName::Metrics,
        'status' => LifecycleStatus::Failed,
        'failed_step' => 'converge:metrics-runtime',
        'error_code' => 'metrics.runtime_failed',
    ]);
    $vpn = new class implements GatewayVpnConverger
    {
        public int $calls = 0;

        public function converge(Node $gateway, BootstrapGatewayData $data): void
        {
            $this->calls++;
        }
    };
    $web = new class implements GatewayWebConverger
    {
        public int $calls = 0;

        public function converge(string $hostname, string $wireguardIp): void
        {
            $this->calls++;
        }
    };
    $selfAccess = new class implements GatewaySelfAccessConverger
    {
        public int $calls = 0;

        public function converge(Node $node): string
        {
            $this->calls++;

            return 'SHA256:gateway';
        }
    };
    $action = bootstrap_gateway_action(
        $orbitHome,
        vpn: $vpn,
        web: $web,
        selfAccess: $selfAccess,
    );

    try {
        expect(fn () => $action->execute(bootstrap_gateway_action_data()))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)
                    ->toBe('unknown')
                    ->and($exception->errorCode)
                    ->toBe('gateway.bootstrap_failed')
                    ->and($exception->getPrevious())
                    ->toBeInstanceOf(RoleAssignmentException::class);
            });

        $failed = $node->refresh();

        expect($failed->status)
            ->toBe(LifecycleStatus::Failed)
            ->and($failed->getAttribute('failed_step'))
            ->toBe('unknown')
            ->and($failed->getAttribute('error_code'))
            ->toBe('gateway.bootstrap_failed')
            ->and(bootstrap_gateway_role_states($failed))
            ->toBe([
                'gateway' => [
                    'status' => LifecycleStatus::Failed,
                    'failed_step' => 'unknown',
                    'error_code' => 'gateway.bootstrap_failed',
                ],
                'metrics' => [
                    'status' => LifecycleStatus::Failed,
                    'failed_step' => 'converge:metrics-runtime',
                    'error_code' => 'metrics.runtime_failed',
                ],
            ])
            ->and(bootstrap_gateway_role_states($vpnOwner))
            ->toBe([
                'vpn' => [
                    'status' => LifecycleStatus::Active,
                    'failed_step' => null,
                    'error_code' => null,
                ],
            ])
            ->and($vpn->calls)
            ->toBe(0)
            ->and($web->calls)
            ->toBe(0)
            ->and($selfAccess->calls)
            ->toBe(0);
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('rejects unsupported local gateway operating systems before any persistence or host mutation', function (?string $fixtureContents): void {
    $orbitHome = sys_get_temp_dir().'/orbit-bootstrap-'.(string) Str::uuid();
    $osReleasePath = sys_get_temp_dir().'/orbit-gateway-os-release-'.(string) Str::uuid();

    if ($fixtureContents !== null) {
        file_put_contents($osReleasePath, $fixtureContents);
    }

    $processes = new class implements ProcessRunner
    {
        public int $calls = 0;

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->calls++;

            return new CommandResult(0, '', '', 1, false);
        }
    };
    $vpn = new class implements GatewayVpnConverger
    {
        public int $calls = 0;

        public function converge(Node $gateway, BootstrapGatewayData $data): void
        {
            $this->calls++;
        }
    };
    $web = new class implements GatewayWebConverger
    {
        public int $calls = 0;

        public function converge(string $hostname, string $wireguardIp): void
        {
            $this->calls++;
        }
    };
    $action = new BootstrapGatewayAction(
        assignRole: app(AssignRoleAction::class),
        identity: new GatewayBootstrapIdentityValidator,
        operatingSystem: new GatewayOperatingSystemGuard($osReleasePath),
        vpnSettings: app(VpnSettings::class),
        processes: $processes,
        files: new ProtectedFileWriter,
        vpn: $vpn,
        web: $web,
        selfAccess: gateway_self_access_noop(),
        orbitHome: $orbitHome,
    );

    try {
        try {
            $action->execute(bootstrap_gateway_action_data());

            throw new LogicException('Unsupported local gateway operating systems must fail.');
        } catch (NodeProvisioningException $exception) {
            expect($exception->step)
                ->toBe('operating-system')
                ->and($exception->errorCode)
                ->toBe('gateway.operating_system_unsupported');
        }

        expect(Node::query()->count())
            ->toBe(0)
            ->and(is_dir($orbitHome))
            ->toBeFalse()
            ->and($processes->calls)
            ->toBe(0)
            ->and($vpn->calls)
            ->toBe(0)
            ->and($web->calls)
            ->toBe(0);
    } finally {
        if (is_file($osReleasePath)) {
            unlink($osReleasePath);
        }
        new Filesystem()->deleteDirectory($orbitHome);
    }
})->with([
    'unsupported Ubuntu release' => ["ID=ubuntu\nVERSION_CODENAME=unsupported\n"],
    'Debian' => ["ID=debian\nVERSION_CODENAME=resolute\n"],
    'malformed release' => ["ID=ubuntu\nVERSION_CODENAME='resolute extra'\n"],
    'missing release file' => [null],
]);

function bootstrap_gateway_action(
    string $orbitHome,
    ?GatewayVpnConverger $vpn = null,
    ?GatewayWebConverger $web = null,
    ?GatewaySelfAccessConverger $selfAccess = null,
): BootstrapGatewayAction {
    return new BootstrapGatewayAction(
        assignRole: app(AssignRoleAction::class),
        identity: new GatewayBootstrapIdentityValidator,
        operatingSystem: bootstrap_gateway_resolute_guard(),
        vpnSettings: app(VpnSettings::class),
        processes: new NativeProcessRunner,
        files: new ProtectedFileWriter,
        vpn: $vpn ?? gateway_vpn_noop(),
        web: $web ?? new class implements GatewayWebConverger
        {
            public function converge(string $hostname, string $wireguardIp): void {}
        },
        selfAccess: $selfAccess ?? gateway_self_access_noop(),
        orbitHome: $orbitHome,
    );
}

function bootstrap_gateway_existing_node(
    string $name = 'gateway',
    string $publicHost = '85.9.218.89',
    string $wireguardIp = '10.44.0.1',
): Node {
    $cluster = Cluster::query()->create(['name' => "{$name}-cluster"]);

    return Node::query()->create([
        'name' => $name,
        'cluster_id' => $cluster->id,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => $publicHost,
        'user' => 'orbit',
        'wireguard_ip' => $wireguardIp,
    ]);
}

/** @return array<string, array{status: LifecycleStatus, failed_step: ?string, error_code: ?string}> */
function bootstrap_gateway_role_states(Node $node): array
{
    return $node
        ->roles()
        ->orderBy('role')
        ->get()
        ->mapWithKeys(static fn (NodeRole $assignment): array => [
            $assignment->role->value => [
                'status' => $assignment->status,
                'failed_step' => $assignment->failed_step,
                'error_code' => $assignment->error_code,
            ],
        ])
        ->all();
}

function gateway_vpn_noop(): GatewayVpnConverger
{
    return new class implements GatewayVpnConverger
    {
        public function converge(Node $gateway, BootstrapGatewayData $data): void {}
    };
}

function gateway_self_access_noop(): GatewaySelfAccessConverger
{
    return new class implements GatewaySelfAccessConverger
    {
        public function converge(Node $node): string
        {
            return 'SHA256:gateway';
        }
    };
}

function bootstrap_gateway_resolute_guard(): GatewayOperatingSystemGuard
{
    $path = sys_get_temp_dir().'/orbit-gateway-os-release-'.(string) Str::uuid();
    file_put_contents($path, data: "ID=ubuntu\nVERSION_CODENAME=resolute\n");

    return new GatewayOperatingSystemGuard($path);
}

function bootstrap_gateway_action_data(): BootstrapGatewayData
{
    return new BootstrapGatewayData(
        publicHost: '85.9.218.89',
        wireguardIp: '10.44.0.1',
        wireguardSubnet: '10.44.0.0/24',
        wireguardEndpoint: '85.9.218.89:51820',
        dnsServer: '10.44.0.1',
        domain: 'orbit',
    );
}

function gateway_root_ca_pair_matches(string $orbitHome): bool
{
    $processes = new NativeProcessRunner;
    $certificatePublicKey = $processes->run(new ProcessInvocation([
        'openssl',
        'x509',
        '-in',
        $orbitHome.'/ca/root.pem',
        '-pubkey',
        '-noout',
    ]));
    $privatePublicKey = $processes->run(new ProcessInvocation([
        'openssl',
        'pkey',
        '-in',
        $orbitHome.'/ca/root.key',
        '-pubout',
    ]));

    return
        $certificatePublicKey->succeeded()
        && $privatePublicKey->succeeded()
        && trim($certificatePublicKey->stdout) === trim($privatePublicKey->stdout);
}

function gateway_root_ca_validity_days(string $orbitHome): int
{
    $dates = new NativeProcessRunner()->run(new ProcessInvocation([
        'openssl',
        'x509',
        '-in',
        $orbitHome.'/ca/root.pem',
        '-noout',
        '-dates',
    ]))->stdout;
    $notBefore = [];
    $notAfter = [];
    preg_match('/notBefore=(.+)/', $dates, $notBefore);
    preg_match('/notAfter=(.+)/', $dates, $notAfter);
    $startsAt = new DateTimeImmutable($notBefore[1]);
    $expiresAt = new DateTimeImmutable($notAfter[1]);
    $days = $startsAt->diff($expiresAt)->days;

    return is_int($days) ? $days : 0;
}
