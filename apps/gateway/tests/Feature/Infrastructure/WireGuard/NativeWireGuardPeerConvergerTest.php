<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\RoleName;
use App\Domain\Settings\SettingRepository;
use App\Domain\WireGuard\GatewayPeerProjectionManager;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\Files\ProtectedFileWriter;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\WireGuard\NativeGatewayPeerProjectionManager;
use App\Infrastructure\WireGuard\NativeWireGuardPeerConverger;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Infrastructure\WireGuard\WireGuardServerConfigRenderer;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** @mago-expect lint:halstead This end-to-end interaction test keeps the command ordering in one observable flow. */
it('validates a candidate config under /etc/wireguard before replacing the live server config', function (): void {
    $orbitHome = sys_get_temp_dir().'/orbit-vpn-'.Str::uuid();
    mkdir(directory: $orbitHome.'/wireguard', permissions: 0o700, recursive: true);
    file_put_contents(
        filename: $orbitHome.'/wireguard/private.key',
        data: str_repeat(string: 'S', times: 43).'=',
    );
    file_put_contents(
        filename: $orbitHome.'/wireguard/public.key',
        data: str_repeat(string: 'P', times: 43).'=',
    );

    try {
        $gateway = Node::query()->create([
            'name' => 'gateway',
            'public_ssh_host' => '85.9.218.89',
            'wireguard_ip' => '10.44.0.1',
            'wireguard_public_key' => str_repeat(string: 'P', times: 43).'=',
        ]);
        $gateway->roles()->create(['role' => RoleName::Vpn]);
        $peer = Node::query()->create([
            'name' => 'app-dev',
            'public_ssh_host' => '94.237.40.75',
            'wireguard_ip' => '10.44.0.2',
            'tld' => 'custom.internal',
        ]);
        $settings = new VpnSettings(app(SettingRepository::class));
        $settings->configure(
            subnet: '10.44.0.0/24',
            endpoint: '10.0.0.2:51820',
            dnsServer: '10.0.0.2',
        );

        $processes = new class implements ProcessRunner {
            /** @var list<ProcessInvocation> */
            public array $calls = [];

            public function run(ProcessInvocation $invocation): CommandResult
            {
                $this->calls[] = $invocation;

                return new CommandResult(0, '', '', 2, false);
            }
        };
        $ssh = new class implements SshExecutor {
            /** @var list<RemoteCommand> */
            public array $commands = [];

            public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
            {
                $this->commands[] = $command;

                return new CommandResult(0, str_repeat(string: 'A', times: 43)."=\n", '', 2, false);
            }
        };
        $converger = new NativeWireGuardPeerConverger(
            configuration: new VpnConfigurationRepository($settings, $orbitHome),
            gatewayPeers: new NativeGatewayPeerProjectionManager(
                configuration: new VpnConfigurationRepository($settings, $orbitHome),
                serverRenderer: new WireGuardServerConfigRenderer,
                files: new ProtectedFileWriter,
                processes: $processes,
                orbitHome: $orbitHome,
            ),
            ssh: $ssh,
        );

        $converger->converge(
            $peer,
            new SshConnection(
                host: '94.237.40.75',
                user: 'orbit',
                port: 22,
                identityFile: '/tmp/key',
                knownHostsFile: '/tmp/known_hosts',
            ),
            true,
        );

        expect($peer->refresh()->wireguard_public_key)
            ->toBe(str_repeat(string: 'A', times: 43).'=')
            ->and($processes->calls)
            ->toHaveCount(5)
            ->and($processes->calls[0]->arguments)
            ->toBe([
                'sudo',
                'install',
                '-D',
                '-o',
                'root',
                '-g',
                'root',
                '-m',
                '0600',
                '--',
                $orbitHome.'/generated/wireguard/orbit.conf',
                '/etc/wireguard/orbit-candidate.conf',
            ])
            ->and($processes->calls[1]->arguments)
            ->toBe([
                'sudo',
                'wg-quick',
                'strip',
                '/etc/wireguard/orbit-candidate.conf',
            ])
            ->and($processes->calls[2]->arguments)
            ->toBe(['sudo', 'bash', '-seu'])
            ->and($processes->calls[2]->input)
            ->toContain('cp --preserve=mode,ownership -- "$live" "$backup"')
            ->and($processes->calls[3]->arguments)
            ->toBe([
                'sudo',
                'mv',
                '-f',
                '--',
                '/etc/wireguard/orbit-candidate.conf',
                '/etc/wireguard/orbit.conf',
            ])
            ->and($processes->calls[4]->arguments)
            ->toBe(['sudo', 'bash', '-seu'])
            ->and($processes->calls[4]->input)
            ->toContain(
                'wg syncconf orbit "$runtime_config"',
                'systemctl start wg-quick@orbit',
                'mv -fT -- "$backup" "$live"',
                'sync_live || systemctl restart wg-quick@orbit || true',
            )
            ->and($ssh->commands)
            ->toHaveCount(2)
            ->and(file_get_contents($orbitHome.'/generated/wireguard/orbit.conf'))
            ->toContain('AllowedIPs = 10.44.0.2/32');

        expect($ssh->commands[1]->input)
            ->toContain(
                'candidate=/etc/wireguard/orbit-candidate.conf',
                'exec 9>/run/lock/orbit-wireguard-peer.lock',
                'flock -w 30 9',
                'wg-quick strip "$candidate" >/dev/null',
                'mv -f -- "$candidate" "$live"',
                'cp -a --no-dereference -- "$live" "$backup"',
                'mv -fT -- "$restore_candidate" "$live"',
                'systemctl restart wg-quick@orbit || return 1',
                'printf -v dns_server_escaped \'%q\' "$dns_server"',
                'printf -v domain_escaped \'%q\' "~$domain"',
                'app_dev_tld=$8',
                'dns_mode=$9',
                'operator_dns=${10}',
                'transaction_mode=${11}',
                'dns_state=/etc/wireguard/orbit.dns-link',
                'restore_dns() {',
                'if [ "$dns_mode" = wireguard ]; then',
                'if [ "$dns_mode" != operator ] && [ -s "$dns_state" ]; then',
                'operator_dns_line="DNS = $operator_dns_escaped"',
                'PostUp = resolvectl dns %i $dns_server_escaped; resolvectl domain %i $dns_domains',
                'PreDown = resolvectl revert %i',
                'route=$(ip -o route get "$dns_server")',
                'if [[ "$route" =~ [[:space:]]dev[[:space:]]([^[:space:]]+) ]]; then',
                'Could not resolve DNS interface.',
                'resolvectl dns "$dns_link" "$dns_server"',
                'resolvectl_domain=("~$domain" "~$app_dev_tld")',
                'printf \'%s\\n%s\\n%s\\n\' "$dns_link" "$dns_server" "$domain" > "$dns_state_candidate"',
            )
            ->not->toContain(
                'candidate=$(mktemp)',
                'PostUp = route=',
                'PreDown = route=',
                '| sed ',
            );

        expect($ssh->commands[1]->arguments)
            ->toContain('custom.internal');

        expect(array_slice($ssh->commands[1]->arguments, -3))
            ->toBe(['operator', '10.44.0.1', 'finalize']);

        $remoteScript = $ssh->commands[1]->input ?? '';
        $dnsStateWrite = mb_strpos(
            haystack: $remoteScript,
            needle: 'printf \'%s\\n%s\\n%s\\n\' "$dns_link" "$dns_server" "$domain" > "$dns_state_candidate"',
        );
        $backupRemoval = mb_strrpos(haystack: $remoteScript, needle: 'rm -f -- "$backup"');

        expect($dnsStateWrite)
            ->toBeInt()
            ->and($backupRemoval)
            ->toBeInt()
            ->and($dnsStateWrite)
            ->toBeLessThan($backupRemoval);
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('does not replace or restart the live service when candidate validation fails', function (): void {
    $orbitHome = sys_get_temp_dir().'/orbit-vpn-'.Str::uuid();
    mkdir(directory: $orbitHome.'/wireguard', permissions: 0o700, recursive: true);
    file_put_contents(
        filename: $orbitHome.'/wireguard/private.key',
        data: str_repeat(string: 'S', times: 43).'=',
    );
    file_put_contents(
        filename: $orbitHome.'/wireguard/public.key',
        data: str_repeat(string: 'P', times: 43).'=',
    );

    try {
        $gateway = Node::query()->create([
            'name' => 'gateway',
            'public_ssh_host' => '85.9.218.89',
            'wireguard_ip' => '10.44.0.1',
            'wireguard_public_key' => str_repeat(string: 'P', times: 43).'=',
        ]);
        $gateway->roles()->create(['role' => RoleName::Vpn]);
        $peer = Node::query()->create([
            'name' => 'app-dev',
            'public_ssh_host' => '94.237.40.75',
            'wireguard_ip' => '10.44.0.2',
        ]);
        $settings = new VpnSettings(app(SettingRepository::class));
        $settings->configure(
            subnet: '10.44.0.0/24',
            endpoint: '10.0.0.2:51820',
            dnsServer: '10.0.0.2',
        );

        $processes = new class implements ProcessRunner {
            /** @var list<ProcessInvocation> */
            public array $calls = [];

            public function run(ProcessInvocation $invocation): CommandResult
            {
                $this->calls[] = $invocation;

                if (
                    $invocation->arguments === [
                        'sudo',
                        'wg-quick',
                        'strip',
                        '/etc/wireguard/orbit-candidate.conf',
                    ]
                ) {
                    return new CommandResult(1, '', 'Permission denied', 2, false);
                }

                return new CommandResult(0, '', '', 2, false);
            }
        };
        $ssh = new class implements SshExecutor {
            /** @var list<RemoteCommand> */
            public array $commands = [];

            public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
            {
                $this->commands[] = $command;

                return new CommandResult(0, str_repeat(string: 'A', times: 43)."=\n", '', 2, false);
            }
        };
        $converger = new NativeWireGuardPeerConverger(
            configuration: new VpnConfigurationRepository($settings, $orbitHome),
            gatewayPeers: new NativeGatewayPeerProjectionManager(
                configuration: new VpnConfigurationRepository($settings, $orbitHome),
                serverRenderer: new WireGuardServerConfigRenderer,
                files: new ProtectedFileWriter,
                processes: $processes,
                orbitHome: $orbitHome,
            ),
            ssh: $ssh,
        );

        expect(fn () => $converger->converge($peer, new SshConnection(
            host: '94.237.40.75',
            user: 'orbit',
            port: 22,
            identityFile: '/tmp/key',
            knownHostsFile: '/tmp/known_hosts',
        )))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)
                    ->toBe('wireguard-server-validate')
                    ->and($exception->errorCode)
                    ->toBe('vpn.server_config_invalid');
            });

        expect($processes->calls)
            ->toHaveCount(3)
            ->and($processes->calls[0]->arguments[11])
            ->toBe('/etc/wireguard/orbit-candidate.conf')
            ->and($processes->calls[1]->arguments)
            ->toBe([
                'sudo',
                'wg-quick',
                'strip',
                '/etc/wireguard/orbit-candidate.conf',
            ])
            ->and($processes->calls[2]->arguments)
            ->toBe([
                'sudo',
                'rm',
                '-f',
                '--',
                '/etc/wireguard/orbit-candidate.conf',
            ])
            ->and($ssh->commands)
            ->toHaveCount(1)
            ->and($peer->refresh()->wireguard_public_key)
            ->toBe(str_repeat(string: 'A', times: 43).'=');
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('attempts candidate cleanup and preserves the original failure when atomic replace fails', function (): void {
    $orbitHome = sys_get_temp_dir().'/orbit-vpn-'.Str::uuid();
    mkdir(directory: $orbitHome.'/wireguard', permissions: 0o700, recursive: true);
    file_put_contents(
        filename: $orbitHome.'/wireguard/private.key',
        data: str_repeat(string: 'S', times: 43).'=',
    );
    file_put_contents(
        filename: $orbitHome.'/wireguard/public.key',
        data: str_repeat(string: 'P', times: 43).'=',
    );

    try {
        $gateway = Node::query()->create([
            'name' => 'gateway',
            'public_ssh_host' => '85.9.218.89',
            'wireguard_ip' => '10.44.0.1',
            'wireguard_public_key' => str_repeat(string: 'P', times: 43).'=',
        ]);
        $gateway->roles()->create(['role' => RoleName::Vpn]);
        $peer = Node::query()->create([
            'name' => 'app-dev',
            'public_ssh_host' => '94.237.40.75',
            'wireguard_ip' => '10.44.0.2',
        ]);
        $settings = new VpnSettings(app(SettingRepository::class));
        $settings->configure(
            subnet: '10.44.0.0/24',
            endpoint: '10.0.0.2:51820',
            dnsServer: '10.0.0.2',
        );

        $processes = new class implements ProcessRunner {
            /** @var list<ProcessInvocation> */
            public array $calls = [];

            public function run(ProcessInvocation $invocation): CommandResult
            {
                $this->calls[] = $invocation;

                if (
                    $invocation->arguments === [
                        'sudo',
                        'mv',
                        '-f',
                        '--',
                        '/etc/wireguard/orbit-candidate.conf',
                        '/etc/wireguard/orbit.conf',
                    ]
                ) {
                    return new CommandResult(1, '', 'Device or resource busy', 2, false);
                }

                if (
                    $invocation->arguments === [
                        'sudo',
                        'rm',
                        '-f',
                        '--',
                        '/etc/wireguard/orbit-candidate.conf',
                        '/etc/wireguard/.orbit.conf.rollback',
                    ]
                ) {
                    return new CommandResult(1, '', 'Permission denied', 2, false);
                }

                return new CommandResult(0, '', '', 2, false);
            }
        };
        $ssh = new class implements SshExecutor {
            /** @var list<RemoteCommand> */
            public array $commands = [];

            public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
            {
                $this->commands[] = $command;

                return new CommandResult(0, str_repeat(string: 'A', times: 43)."=\n", '', 2, false);
            }
        };
        $converger = new NativeWireGuardPeerConverger(
            configuration: new VpnConfigurationRepository($settings, $orbitHome),
            gatewayPeers: new NativeGatewayPeerProjectionManager(
                configuration: new VpnConfigurationRepository($settings, $orbitHome),
                serverRenderer: new WireGuardServerConfigRenderer,
                files: new ProtectedFileWriter,
                processes: $processes,
                orbitHome: $orbitHome,
            ),
            ssh: $ssh,
        );

        expect(fn () => $converger->converge($peer, new SshConnection(
            host: '94.237.40.75',
            user: 'orbit',
            port: 22,
            identityFile: '/tmp/key',
            knownHostsFile: '/tmp/known_hosts',
        )))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)
                    ->toBe('wireguard-server-install')
                    ->and($exception->errorCode)
                    ->toBe('vpn.server_config_install_failed')
                    ->and($exception->result?->stderr)
                    ->toBe('Device or resource busy');
            });

        expect($processes->calls)
            ->toHaveCount(5)
            ->and($processes->calls[1]->arguments)
            ->toBe([
                'sudo',
                'wg-quick',
                'strip',
                '/etc/wireguard/orbit-candidate.conf',
            ])
            ->and($processes->calls[2]->arguments)
            ->toBe(['sudo', 'bash', '-seu'])
            ->and($processes->calls[3]->arguments)
            ->toBe([
                'sudo',
                'mv',
                '-f',
                '--',
                '/etc/wireguard/orbit-candidate.conf',
                '/etc/wireguard/orbit.conf',
            ])
            ->and($processes->calls[4]->arguments)
            ->toBe([
                'sudo',
                'rm',
                '-f',
                '--',
                '/etc/wireguard/orbit-candidate.conf',
                '/etc/wireguard/.orbit.conf.rollback',
            ])
            ->and($ssh->commands)
            ->toHaveCount(1)
            ->and($peer->refresh()->wireguard_public_key)
            ->toBe(str_repeat(string: 'A', times: 43).'=');
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('restores and restarts the previous server config when peer publication cannot activate it', function (): void {
    $processes = new class implements ProcessRunner {
        /** @var list<ProcessInvocation> */
        public array $calls = [];

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->calls[] = $invocation;

            if (
                $invocation->arguments === ['sudo', 'bash', '-seu']
                && str_contains($invocation->input ?? '', 'wg syncconf orbit "$runtime_config"')
            ) {
                return new CommandResult(1, '', 'new server config failed', 2, false);
            }

            return new CommandResult(0, '', '', 2, false);
        }
    };
    $ssh = new class implements SshExecutor {
        /** @var list<RemoteCommand> */
        public array $commands = [];

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->commands[] = $command;

            return new CommandResult(0, str_repeat(string: 'A', times: 43)."=\n", '', 2, false);
        }
    };
    [$converger, $peer, $connection, $orbitHome] = wireguard_peer_harness($processes, $ssh);

    try {
        expect(fn () => $converger->converge($peer, $connection))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)
                    ->toBe('wireguard-server-restart')
                    ->and($exception->errorCode)
                    ->toBe('vpn.server_start_failed')
                    ->and($exception->result?->stderr)
                    ->toBe('new server config failed');
            });
        $scripts = Collection::make($processes->calls)
            ->filter(static fn (ProcessInvocation $call): bool => $call->arguments === ['sudo', 'bash', '-seu'])
            ->pluck('input')
            ->filter(static fn (mixed $input): bool => is_string($input))
            ->implode("\n");

        expect($scripts)
            ->toContain(
                'cp --preserve=mode,ownership -- "$live" "$backup"',
                'mv -fT -- "$backup" "$live"',
                'sync_live || systemctl restart wg-quick@orbit || true',
            )
            ->and($ssh->commands)
            ->toHaveCount(1);
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('restores and restarts the previous peer config when remote activation fails', function (): void {
    $processes = new class implements ProcessRunner {
        public function run(ProcessInvocation $invocation): CommandResult
        {
            return new CommandResult(0, '', '', 2, false);
        }
    };
    $ssh = new class implements SshExecutor {
        /** @var list<RemoteCommand> */
        public array $commands = [];

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->commands[] = $command;

            if (count($this->commands) === 1) {
                return new CommandResult(0, str_repeat(string: 'A', times: 43)."=\n", '', 2, false);
            }

            return new CommandResult(1, '', 'new peer config failed', 2, false);
        }
    };
    $sleeps = [];
    [$converger, $peer, $connection, $orbitHome] = wireguard_peer_harness(
        $processes,
        $ssh,
        static function (int $microseconds) use (&$sleeps): int {
            $sleeps[] = $microseconds;

            return 0;
        },
    );

    try {
        expect(fn () => $converger->converge($peer, $connection))
            ->toThrow(function (NodeProvisioningException $exception): void {
                expect($exception->step)
                    ->toBe('wireguard-peer-install')
                    ->and($exception->errorCode)
                    ->toBe('vpn.peer_config_failed')
                    ->and($exception->result?->stderr)
                    ->toBe('new peer config failed');
            });

        expect($ssh->commands[1]->input)
            ->toContain(
                'cp -a --no-dereference -- "$live" "$backup"',
                'mv -fT -- "$restore_candidate" "$live"',
                'systemctl restart wg-quick@orbit || return 1',
            );
        expect($sleeps)->toBeEmpty();
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('restores exact peer files and an active enabled service after a late remote failure', function (): void {
    $harness = remote_wireguard_peer_install_harness(
        filesPresent: true,
        activeState: 'active',
        enabledState: 'enabled',
    );

    try {
        $harness->failWireGuard();
        $result = $harness->convergeRecoverably();

        expect($result['succeeded'])
            ->toBeFalse()
            ->and($result['exception'])
            ->toBeInstanceOf(NodeProvisioningException::class)
            ->and($result['exception']->errorCode)
            ->toBe('vpn.peer_config_failed')
            ->and($result['live'])
            ->toBe($harness->originalLive())
            ->and($result['dns'])
            ->toBe($harness->originalDns())
            ->and($result['service_state'])
            ->toBe(['active', 'enabled'])
            ->and($result['command_log'])
            ->toContain('wg show orbit public-key')
            ->and($result['rollback_artifacts'])
            ->toBeEmpty();
    } finally {
        $harness->cleanup();
    }
});

it('uses the persisted peer key for recoverable convergence without rewriting the public key file', function (): void {
    $harness = remote_wireguard_peer_install_harness(
        filesPresent: true,
        activeState: 'active',
        enabledState: 'enabled',
    );

    try {
        $result = $harness->convergeRecoverably();

        expect($result['succeeded'])
            ->toBeTrue($result['stderr'])
            ->and(file_get_contents($harness->root().'/wireguard/orbit.public'))
            ->toBe("prior-public-key\n")
            ->and($result['rollback_artifacts'])
            ->toBeEmpty();
    } finally {
        $harness->cleanup();
    }
});

it('rejects a missing or mismatched recoverable key before persisted, gateway, or peer mutation', function (
    CommandResult $keyResult,
    string $errorCode,
): void {
    $processes = new class implements ProcessRunner {
        /** @var list<ProcessInvocation> */
        public array $calls = [];

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->calls[] = $invocation;

            return new CommandResult(0, '', '', 1, false);
        }
    };
    $ssh = new class($keyResult) implements SshExecutor {
        /** @var list<RemoteCommand> */
        public array $commands = [];

        public function __construct(
            private readonly CommandResult $keyResult,
        ) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->commands[] = $command;

            return $this->keyResult;
        }
    };
    [$converger, $peer, $connection, $orbitHome] = wireguard_peer_harness($processes, $ssh);
    $peer->update(['wireguard_public_key' => str_repeat(string: 'A', times: 43).'=']);
    $completionCalled = false;

    try {
        expect(fn () => $converger->convergeRecoverably(
            $peer,
            $connection,
            function () use (&$completionCalled): void {
                $completionCalled = true;
            },
        ))
            ->toThrow(
                fn (NodeProvisioningException $exception): bool => $exception->errorCode === $errorCode,
            );

        expect($peer->refresh()->wireguard_public_key)
            ->toBe(str_repeat(string: 'A', times: 43).'=')
            ->and($completionCalled)
            ->toBeFalse()
            ->and($ssh->commands)
            ->toHaveCount(1)
            ->and($processes->calls)
            ->toBeEmpty();
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
})->with([
    'missing key' => [new CommandResult(44, '', '', 1, false), 'vpn.peer_key_missing'],
    'mismatched key' => [
        new CommandResult(0, str_repeat(string: 'B', times: 43)."=\n", '', 1, false),
        'vpn.peer_key_mismatch',
    ],
]);

it('rolls back exact peer state when the recoverable completion fails', function (): void {
    $harness = remote_wireguard_peer_install_harness(
        filesPresent: true,
        activeState: 'active',
        enabledState: 'enabled',
    );

    try {
        $result = $harness->convergeRecoverably(static function (): void {
            throw new RuntimeException('completion failed');
        });

        expect($result['exception'])
            ->toBeInstanceOf(RuntimeException::class)
            ->and($result['live'])
            ->toBe($harness->originalLive())
            ->and($result['dns'])
            ->toBe($harness->originalDns())
            ->and($result['service_state'])
            ->toBe(['active', 'enabled'])
            ->and($result['command_log'])
            ->toContain(
                'resolvectl revert orbit',
                'resolvectl dns old0 10.43.0.53',
                'resolvectl domain old0 ~old.orbit.internal',
            )
            ->and($result['rollback_artifacts'])
            ->toBeEmpty();
    } finally {
        $harness->cleanup();
    }
});

it('rolls back exact peer state when recoverable commit validation fails', function (): void {
    $harness = remote_wireguard_peer_install_harness(
        filesPresent: true,
        activeState: 'inactive',
        enabledState: 'disabled',
    );

    try {
        $result = $harness->convergeRecoverably(function () use ($harness): void {
            $harness->failWireGuard();
        });

        expect($result['exception'])
            ->toBeInstanceOf(NodeProvisioningException::class)
            ->and($result['exception']->errorCode)
            ->toBe('vpn.peer_commit_failed')
            ->and($result['live'])
            ->toBe($harness->originalLive())
            ->and($result['dns'])
            ->toBe($harness->originalDns())
            ->and($result['service_state'])
            ->toBe(['inactive', 'disabled'])
            ->and($result['rollback_artifacts'])
            ->toBeEmpty();
    } finally {
        $harness->cleanup();
    }
});

it('rolls back exact peer state when recoverable commit cleanup fails', function (): void {
    $harness = remote_wireguard_peer_install_harness(
        filesPresent: true,
        activeState: 'active',
        enabledState: 'enabled-runtime',
    );

    try {
        $result = $harness->convergeRecoverably(function () use ($harness): void {
            $harness->failCommitCleanup();
        });

        expect($result['exception'])
            ->toBeInstanceOf(NodeProvisioningException::class)
            ->and($result['exception']->errorCode)
            ->toBe('vpn.peer_commit_failed')
            ->and($result['live'])
            ->toBe($harness->originalLive())
            ->and($result['dns'])
            ->toBe($harness->originalDns())
            ->and($result['service_state'])
            ->toBe(['active', 'enabled-runtime'])
            ->and($result['rollback_artifacts'])
            ->toBeEmpty();
    } finally {
        $harness->cleanup();
    }
});

it('keeps the private peer key out of remote arguments and failure output', function (): void {
    $harness = remote_wireguard_peer_install_harness(
        filesPresent: true,
        activeState: 'active',
        enabledState: 'enabled',
    );

    try {
        $result = $harness->convergeRecoverably(static function (): void {
            throw new RuntimeException('bounded completion failure');
        });
        $privateKey = str_repeat(string: 'S', times: 43).'=';

        expect(json_encode($result['remote_arguments'], JSON_THROW_ON_ERROR))
            ->not->toContain($privateKey)->and($result['stderr'])
            ->not->toContain($privateKey);
    } finally {
        $harness->cleanup();
    }
});

it('reports rollback failure without discarding reusable recovery artifacts', function (): void {
    $harness = remote_wireguard_peer_install_harness(
        filesPresent: true,
        activeState: 'active',
        enabledState: 'enabled',
    );

    try {
        $harness->failRollback();
        $result = $harness->convergeRecoverably(static function (): void {
            throw new RuntimeException('completion failed');
        });

        expect($result['exception'])
            ->toBeInstanceOf(NodeProvisioningException::class)
            ->and($result['exception']->errorCode)
            ->toBe('vpn.peer_rollback_failed')
            ->and($result['rollback_artifacts'])
            ->not->toBeEmpty();
    } finally {
        $harness->cleanup();
    }
});

it('retains peer rollback artifacts until a recoverable continuation commits', function (): void {
    $harness = remote_wireguard_peer_install_harness(
        filesPresent: true,
        activeState: 'active',
        enabledState: 'enabled',
    );

    try {
        $duringCompletion = null;
        $result = $harness->convergeRecoverably(function () use ($harness, &$duringCompletion): void {
            $duringCompletion = $harness->state();
        });

        expect($result['succeeded'])
            ->toBeTrue()
            ->and($duringCompletion['rollback_artifacts'])
            ->not
            ->toBeEmpty()
            ->and($duringCompletion['service_state'])
            ->toBe(['active', 'enabled'])
            ->and($result['rollback_artifacts'])
            ->toBeEmpty();
    } finally {
        $harness->cleanup();
    }
});

it('fails bounded when a stale recoverable peer transaction is already present', function (): void {
    $harness = remote_wireguard_peer_install_harness(
        filesPresent: true,
        activeState: 'active',
        enabledState: 'enabled',
    );

    try {
        $harness->failRollback();
        $harness->convergeRecoverably(static function (): void {
            throw new RuntimeException('completion failed');
        });
        $harness->allowRollback();

        $retry = $harness->convergeRecoverably();

        expect($retry['exception'])
            ->toBeInstanceOf(NodeProvisioningException::class)
            ->and($retry['exception']->step)
            ->toBe('wireguard-peer-transaction')
            ->and($retry['exception']->errorCode)
            ->toBe('vpn.peer_recovery_pending')
            ->and($retry['exception']->getMessage())
            ->toContain('recovery');

        expect($harness->state()['rollback_artifacts'])->toBeEmpty();

        $nextRetry = $harness->convergeRecoverably();

        expect($nextRetry['succeeded'])->toBeTrue($nextRetry['stderr']);
    } finally {
        $harness->cleanup();
    }
});

it('removes retained peer rollback artifacts after a recoverable commit', function (): void {
    $harness = remote_wireguard_peer_install_harness(
        filesPresent: true,
        activeState: 'active',
        enabledState: 'enabled',
    );

    try {
        $result = $harness->convergeRecoverably();

        expect($result['succeeded'])->toBeTrue()->and($result['rollback_artifacts'])->toBeEmpty();
    } finally {
        $harness->cleanup();
    }
});

it('restores absent peer files and an inactive disabled service after a late remote failure', function (): void {
    $harness = remote_wireguard_peer_install_harness(
        filesPresent: false,
        activeState: 'inactive',
        enabledState: 'disabled',
    );

    try {
        $result = $harness->convergeRecoverably(static function (): void {
            throw new RuntimeException('completion failed');
        });

        expect($result['succeeded'])
            ->toBeFalse()
            ->and($result['live'])
            ->toBeNull()
            ->and($result['dns'])
            ->toBeNull()
            ->and($result['service_state'])
            ->toBe(['inactive', 'disabled'])
            ->and($result['command_log'])
            ->toContain('wg show orbit public-key')
            ->and($result['command_log'])
            ->toContain('systemctl stop wg-quick@orbit')
            ->and($result['rollback_artifacts'])
            ->toBeEmpty();
    } finally {
        $harness->cleanup();
    }
});

it('restores absent peer files when the peer command fails internally after restart', function (): void {
    $harness = remote_wireguard_peer_install_harness(
        filesPresent: false,
        activeState: 'inactive',
        enabledState: 'disabled',
    );
    try {
        $result = $harness->converge(lateFailure: true);
        expect($result['succeeded'])
            ->toBeFalse()
            ->and($result['live'])
            ->toBeNull()
            ->and($result['service_state'])
            ->toBe(['inactive', 'disabled'])
            ->and($result['rollback_artifacts'])
            ->toBeEmpty();
    } finally {
        $harness->cleanup();
    }
});

it('unmasks peer service for publication and restores the mask after a late remote failure', function (): void {
    $harness = remote_wireguard_peer_install_harness(
        filesPresent: true,
        activeState: 'inactive',
        enabledState: 'masked',
    );

    try {
        $result = $harness->convergeRecoverably(static function (): void {
            throw new RuntimeException('completion failed');
        });

        expect($result['succeeded'])
            ->toBeFalse()
            ->and($result['live'])
            ->toBe($harness->originalLive())
            ->and($result['dns'])
            ->toBe($harness->originalDns())
            ->and($result['service_state'])
            ->toBe(['inactive', 'masked'])
            ->and($result['command_log'])
            ->toContain(
                'systemctl unmask wg-quick@orbit',
                'wg show orbit public-key',
                'systemctl mask wg-quick@orbit',
            )
            ->and($result['rollback_artifacts'])
            ->toBeEmpty();
    } finally {
        $harness->cleanup();
    }
});

it('removes peer rollback artifacts after successful publication', function (): void {
    $harness = remote_wireguard_peer_install_harness(
        filesPresent: true,
        activeState: 'active',
        enabledState: 'enabled',
    );

    try {
        $result = $harness->converge(lateFailure: false);

        expect($result['succeeded'])
            ->toBeTrue($result['stderr'])
            ->and($result['rollback_artifacts'])
            ->toBeEmpty();
    } finally {
        $harness->cleanup();
    }
});

it('cleans finalize transaction artifacts when candidate validation fails', function (): void {
    $harness = remote_wireguard_peer_install_harness(
        filesPresent: true,
        activeState: 'active',
        enabledState: 'enabled',
    );

    try {
        $harness->failStrip();
        $result = $harness->converge(lateFailure: false);

        expect($result['succeeded'])->toBeFalse()->and($result['rollback_artifacts'])->toBeEmpty();
        $harness->allowStrip();
        expect($harness->converge(lateFailure: false)['succeeded'])->toBeTrue();
    } finally {
        $harness->cleanup();
    }
});

it('retains finalize artifacts when restoration fails after publication', function (): void {
    $harness = remote_wireguard_peer_install_harness(
        filesPresent: true,
        activeState: 'active',
        enabledState: 'enabled',
    );

    try {
        $harness->failWireGuard();
        $harness->failRestore();
        $result = $harness->converge(lateFailure: true);

        expect($result['succeeded'])
            ->toBeFalse()
            ->and($result['rollback_artifacts'])
            ->not->toBeEmpty();
    } finally {
        $harness->cleanup();
    }
});

it('retains pre-existing recovery artifacts when finalize is refused', function (): void {
    $harness = remote_wireguard_peer_install_harness(
        filesPresent: true,
        activeState: 'active',
        enabledState: 'enabled',
    );

    try {
        $harness->failRollback();
        $harness->convergeRecoverably(static function (): void {
            throw new RuntimeException('completion failed');
        });
        $before = $harness->state()['rollback_artifacts'];
        $result = $harness->converge(lateFailure: false);

        expect($result['succeeded'])->toBeFalse()->and($result['rollback_artifacts'])->toBe($before);
    } finally {
        $harness->cleanup();
    }
});

it('cleans owned retain artifacts when transaction publication fails', function (): void {
    $harness = remote_wireguard_peer_install_harness(
        filesPresent: true,
        activeState: 'active',
        enabledState: 'enabled',
    );

    try {
        $harness->failTransactionPublication();
        $result = $harness->convergeRecoverably();
        expect($result['succeeded'])
            ->toBeFalse()
            ->and($result['live'])
            ->toBe($harness->originalLive())
            ->and($result['service_state'])
            ->toBe(['active', 'enabled'])
            ->and($result['rollback_artifacts'])
            ->toBeEmpty();
        $harness->allowTransactionPublication();
        expect($harness->convergeRecoverably()['succeeded'])->toBeTrue();
    } finally {
        $harness->cleanup();
    }
});

it('restores a DNS state symlink without dereferencing it', function (): void {
    $harness = remote_wireguard_peer_install_harness(
        filesPresent: true,
        activeState: 'inactive',
        enabledState: 'disabled',
        dnsSymlink: true,
    );

    try {
        $result = $harness->convergeRecoverably(static function (): void {
            throw new RuntimeException('completion failed');
        });

        expect($result['dns']['link_target'])->toBe('orbit.dns-link.target');
    } finally {
        $harness->cleanup();
    }
});

it('rejects unsupported systemd enablement states before peer mutation', function (string $enabledState): void {
    $harness = remote_wireguard_peer_install_harness(
        filesPresent: true,
        activeState: 'inactive',
        enabledState: $enabledState,
    );

    try {
        $result = $harness->convergeRecoverably();

        expect($result['succeeded'])
            ->toBeFalse()
            ->and($result['exception'])
            ->toBeInstanceOf(NodeProvisioningException::class)
            ->and($result['exception']->errorCode)
            ->toBe('vpn.peer_state_unsupported')
            ->and($result['live'])
            ->toBe($harness->originalLive())
            ->and($result['dns'])
            ->toBe($harness->originalDns())
            ->and($result['service_state'])
            ->toBe(['inactive', $enabledState])
            ->and($result['rollback_artifacts'])
            ->toBeEmpty()
            ->and($result['command_log'])
            ->not->toContain('wg-quick strip '.$harness->root().'/wireguard/orbit-candidate.conf');
    } finally {
        $harness->cleanup();
    }
})->with([
    'generated',
    'transient',
    'alias',
    'linked',
    'linked-runtime',
]);

it('restores supported persistent and runtime systemd states after recoverable failure', function (
    string $enabledState,
): void {
    $harness = remote_wireguard_peer_install_harness(
        filesPresent: true,
        activeState: 'inactive',
        enabledState: $enabledState,
    );

    try {
        $result = $harness->convergeRecoverably(static function (): void {
            throw new RuntimeException('completion failed');
        });

        expect($result['exception'])
            ->toBeInstanceOf(RuntimeException::class)
            ->and($result['service_state'])
            ->toBe(['inactive', $enabledState])
            ->and($result['rollback_artifacts'])
            ->toBeEmpty();
    } finally {
        $harness->cleanup();
    }
})->with([
    'persistent enabled' => 'enabled',
    'runtime enabled' => 'enabled-runtime',
    'persistent masked' => 'masked',
    'runtime masked' => 'masked-runtime',
    'disabled' => 'disabled',
]);

it('retries a peer install with bounded exponential backoff after ssh transport failures', function (): void {
    $processes = new class implements ProcessRunner {
        public function run(ProcessInvocation $invocation): CommandResult
        {
            return new CommandResult(0, '', '', 2, false);
        }
    };
    $ssh = new class implements SshExecutor {
        public int $peerInstallAttempts = 0;

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            if (
                $command->input !== null
                && str_contains($command->input, 'candidate=/etc/wireguard/orbit-candidate.conf')
            ) {
                $this->peerInstallAttempts++;

                if ($this->peerInstallAttempts < 3) {
                    return new CommandResult(
                        255,
                        '',
                        'ssh: connect to host 10.44.0.7 port 22: No route to host',
                        2,
                        false,
                    );
                }
            }

            return new CommandResult(0, str_repeat(string: 'A', times: 43)."=\n", '', 2, false);
        }
    };
    $sleeps = [];
    [$converger, $peer, $connection, $orbitHome] = wireguard_peer_harness(
        $processes,
        $ssh,
        static function (int $microseconds) use (&$sleeps): int {
            $sleeps[] = $microseconds;

            return 0;
        },
    );

    try {
        $converger->converge($peer, $connection);

        expect($ssh->peerInstallAttempts)
            ->toBe(3)
            ->and($sleeps)
            ->toBe([1_000_000, 2_000_000]);
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('preserves the final transport failure after exhausting peer install retries', function (): void {
    $processes = new class implements ProcessRunner {
        public function run(ProcessInvocation $invocation): CommandResult
        {
            return new CommandResult(0, '', '', 2, false);
        }
    };
    $ssh = new class implements SshExecutor {
        public int $peerInstallAttempts = 0;

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            if (
                $command->input !== null
                && str_contains($command->input, 'candidate=/etc/wireguard/orbit-candidate.conf')
            ) {
                $this->peerInstallAttempts++;

                return new CommandResult(
                    255,
                    '',
                    'ssh: connect to host 10.44.0.7 port 22: No route to host',
                    $this->peerInstallAttempts,
                    false,
                );
            }

            return new CommandResult(0, str_repeat(string: 'A', times: 43)."=\n", '', 2, false);
        }
    };
    $sleeps = [];
    [$converger, $peer, $connection, $orbitHome] = wireguard_peer_harness(
        $processes,
        $ssh,
        static function (int $microseconds) use (&$sleeps): int {
            $sleeps[] = $microseconds;

            return 0;
        },
    );

    try {
        expect(fn () => $converger->converge($peer, $connection))
            ->toThrow(function (NodeProvisioningException $exception) use ($ssh): void {
                expect($exception->errorCode)
                    ->toBe('vpn.peer_config_failed')
                    ->and($exception->result?->exitCode)
                    ->toBe(255)
                    ->and($exception->result?->durationMs)
                    ->toBe(3);
                expect($ssh->peerInstallAttempts)->toBe(3);
            });

        expect($sleeps)->toBe([1_000_000, 2_000_000]);
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('does not retry a connected peer install with an exit-255 semantic failure', function (): void {
    $processes = new class implements ProcessRunner {
        public function run(ProcessInvocation $invocation): CommandResult
        {
            return new CommandResult(0, '', '', 2, false);
        }
    };
    $ssh = new class implements SshExecutor {
        public int $peerInstallAttempts = 0;

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            if (
                $command->input !== null
                && str_contains($command->input, 'candidate=/etc/wireguard/orbit-candidate.conf')
            ) {
                $this->peerInstallAttempts++;

                return new CommandResult(255, '', 'remote script failed', 2, false);
            }

            return new CommandResult(0, str_repeat(string: 'A', times: 43)."=\n", '', 2, false);
        }
    };
    $sleeps = [];
    [$converger, $peer, $connection, $orbitHome] = wireguard_peer_harness(
        $processes,
        $ssh,
        static function (int $microseconds) use (&$sleeps): int {
            $sleeps[] = $microseconds;

            return 0;
        },
    );

    try {
        expect(fn () => $converger->converge($peer, $connection))
            ->toThrow('Could not configure WireGuard on node [app-dev].');
        expect($ssh->peerInstallAttempts)
            ->toBe(1)
            ->and($sleeps)
            ->toBeEmpty();
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('uses a wg-quick compatible candidate filename', function (): void {
    $orbitHome = sys_get_temp_dir().'/orbit-vpn-'.Str::uuid();
    mkdir(directory: $orbitHome.'/wireguard', permissions: 0o700, recursive: true);
    file_put_contents(
        filename: $orbitHome.'/wireguard/private.key',
        data: str_repeat(string: 'S', times: 43).'=',
    );
    file_put_contents(
        filename: $orbitHome.'/wireguard/public.key',
        data: str_repeat(string: 'P', times: 43).'=',
    );

    try {
        $gateway = Node::query()->create([
            'name' => 'gateway',
            'public_ssh_host' => '85.9.218.89',
            'wireguard_ip' => '10.44.0.1',
            'wireguard_public_key' => str_repeat(string: 'P', times: 43).'=',
        ]);
        $gateway->roles()->create(['role' => RoleName::Vpn]);
        $peer = Node::query()->create([
            'name' => 'app-dev',
            'public_ssh_host' => '94.237.40.75',
            'wireguard_ip' => '10.44.0.2',
        ]);
        $settings = new VpnSettings(app(SettingRepository::class));
        $settings->configure(
            subnet: '10.44.0.0/24',
            endpoint: '10.0.0.2:51820',
            dnsServer: '10.0.0.2',
        );

        $processes = new class implements ProcessRunner {
            /** @var list<ProcessInvocation> */
            public array $calls = [];

            public function run(ProcessInvocation $invocation): CommandResult
            {
                $this->calls[] = $invocation;

                return new CommandResult(0, '', '', 2, false);
            }
        };
        $ssh = new class implements SshExecutor {
            public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
            {
                return new CommandResult(0, str_repeat(string: 'A', times: 43)."=\n", '', 2, false);
            }
        };
        $converger = new NativeWireGuardPeerConverger(
            configuration: new VpnConfigurationRepository($settings, $orbitHome),
            gatewayPeers: new NativeGatewayPeerProjectionManager(
                configuration: new VpnConfigurationRepository($settings, $orbitHome),
                serverRenderer: new WireGuardServerConfigRenderer,
                files: new ProtectedFileWriter,
                processes: $processes,
                orbitHome: $orbitHome,
            ),
            ssh: $ssh,
        );

        $converger->converge($peer, new SshConnection(
            host: '94.237.40.75',
            user: 'orbit',
            port: 22,
            identityFile: '/tmp/key',
            knownHostsFile: '/tmp/known_hosts',
        ));

        $candidatePath = Collection::make($processes->calls)
            ->flatMap(static fn (ProcessInvocation $invocation): array => $invocation->arguments)
            ->first(
                static fn (string $argument): bool => (
                    str_starts_with($argument, '/etc/wireguard/')
                    && $argument !== '/etc/wireguard/orbit.conf'
                ),
            );

        expect($candidatePath)
            ->toBe('/etc/wireguard/orbit-candidate.conf')
            ->and(basename($candidatePath, suffix: '.conf'))
            ->toBe('orbit-candidate')
            ->and(strlen(basename($candidatePath, suffix: '.conf')))
            ->toBeLessThanOrEqual(15)
            ->and(preg_match('/^[A-Za-z0-9_=+.-]{1,15}$/', basename($candidatePath, suffix: '.conf')))
            ->toBe(1);
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

/** @return array{NativeWireGuardPeerConverger, Node, SshConnection, string} */
function wireguard_peer_harness(ProcessRunner $processes, SshExecutor $ssh, ?\Closure $sleep = null): array
{
    $orbitHome = sys_get_temp_dir().'/orbit-vpn-'.Str::uuid();
    mkdir(directory: $orbitHome.'/wireguard', permissions: 0o700, recursive: true);
    file_put_contents(
        filename: $orbitHome.'/wireguard/private.key',
        data: str_repeat(string: 'S', times: 43).'=',
    );
    file_put_contents(
        filename: $orbitHome.'/wireguard/public.key',
        data: str_repeat(string: 'P', times: 43).'=',
    );
    $gateway = Node::query()->create([
        'name' => 'gateway',
        'public_ssh_host' => '85.9.218.89',
        'wireguard_ip' => '10.44.0.1',
        'wireguard_public_key' => str_repeat(string: 'P', times: 43).'=',
    ]);
    $gateway->roles()->create(['role' => RoleName::Vpn]);
    $peer = Node::query()->create([
        'name' => 'app-dev',
        'public_ssh_host' => '94.237.40.75',
        'wireguard_ip' => '10.44.0.2',
    ]);
    $settings = new VpnSettings(app(SettingRepository::class));
    $settings->configure(
        subnet: '10.44.0.0/24',
        endpoint: '10.0.0.2:51820',
        dnsServer: '10.0.0.2',
    );

    return [
        new NativeWireGuardPeerConverger(
            configuration: new VpnConfigurationRepository($settings, $orbitHome),
            gatewayPeers: new NativeGatewayPeerProjectionManager(
                configuration: new VpnConfigurationRepository($settings, $orbitHome),
                serverRenderer: new WireGuardServerConfigRenderer,
                files: new ProtectedFileWriter,
                processes: $processes,
                orbitHome: $orbitHome,
            ),
            ssh: $ssh,
            sleep: $sleep,
        ),
        $peer,
        new SshConnection(
            host: '94.237.40.75',
            user: 'orbit',
            port: 22,
            identityFile: '/tmp/key',
            knownHostsFile: '/tmp/known_hosts',
        ),
        $orbitHome,
    ];
}

it('routes the peer TLD with the VPN domain in executable peer installs', function (
    ?string $tld,
    ?string $dnsServer,
    string $expected,
    string $unexpected,
): void {
    $harness = remote_wireguard_peer_install_harness(false, 'inactive', 'disabled', false, $tld, $dnsServer);

    try {
        $result = $harness->converge(false);

        expect($result['succeeded'])->toBeTrue();
        if ($dnsServer === null) {
            expect($result['live']['contents'] ?? '')->toContain($expected);
        } else {
            expect(implode("\n", $result['command_log']))->toContain($expected);
        }
        if ($unexpected !== '') {
            expect($result['live']['contents'] ?? '')->not->toContain($unexpected);
        }
    } finally {
        new Filesystem()->deleteDirectory($harness->root());
    }
})->with([
    'wireguard distinct TLD' => [
        'custom.internal',
        null,
        'PostUp = resolvectl dns %i 10.43.0.53; resolvectl domain %i \\~orbit \\~custom.internal',
        '',
    ],
    'underlay distinct TLD' => ['custom.internal', '192.0.2.53', 'resolvectl domain eth0 ~orbit ~custom.internal', ''],
    'null TLD' => [null, null, 'PostUp = resolvectl dns %i 10.43.0.53; resolvectl domain %i \\~orbit', ''],
    'equal TLD' => [
        'orbit',
        null,
        'PostUp = resolvectl dns %i 10.43.0.53; resolvectl domain %i \\~orbit',
        '\\~orbit \\~orbit',
    ],
]);

/**
 * @mago-expect lint:halstead The harness keeps the complete remote transaction in one executable fixture.
 * @mago-expect lint:excessive-parameter-list The fixture exposes optional DNS inputs for executable route cases.
 * @mago-expect lint:no-boolean-flag-parameter The flag sets the prior managed-file fixture state.
 * @mago-expect lint:cyclomatic-complexity The fixture models each supported file and service state through executable shims.
 */
function remote_wireguard_peer_install_harness(
    bool $filesPresent,
    string $activeState,
    string $enabledState,
    bool $dnsSymlink = false,
    ?string $peerTld = null,
    ?string $dnsServer = null,
): object {
    $root = sys_get_temp_dir().'/orbit-wireguard-peer-shell-'.Str::uuid();
    $filesystem = new Filesystem;
    $filesystem->makeDirectory("{$root}/wireguard", 0o700, true);
    $filesystem->makeDirectory("{$root}/bin", 0o700, true);
    $filesystem->makeDirectory("{$root}/state", 0o700, true);
    file_put_contents("{$root}/state/active", $activeState);
    file_put_contents("{$root}/state/enabled", $enabledState);
    file_put_contents("{$root}/wireguard/orbit.key", str_repeat(string: 'S', times: 43).'=');
    file_put_contents(filename: "{$root}/wireguard/orbit.public", data: "prior-public-key\n");
    file_put_contents("{$root}/wireguard/private.key", str_repeat(string: 'K', times: 43).'=');
    file_put_contents("{$root}/wireguard/public.key", str_repeat(string: 'P', times: 43).'=');

    $originalLiveContent = "[Interface]\nPrivateKey = prior-secret\nAddress = 10.43.0.7/24\n";
    $originalDnsContent = "old0\n10.43.0.53\nold.orbit.internal\n";
    if ($filesPresent) {
        file_put_contents("{$root}/wireguard/orbit.conf", $originalLiveContent);
        chmod(filename: "{$root}/wireguard/orbit.conf", permissions: 0o640);
        $dnsPath = $dnsSymlink ? "{$root}/wireguard/orbit.dns-link.target" : "{$root}/wireguard/orbit.dns-link";
        file_put_contents($dnsPath, $originalDnsContent);
        chmod(filename: $dnsPath, permissions: 0o644);
        if ($dnsSymlink) {
            symlink('orbit.dns-link.target', "{$root}/wireguard/orbit.dns-link");
        }
    }

    remote_wireguard_peer_write_shim(
        root: $root,
        name: 'mv',
        body: <<<SH
            if [ "\${4:-}" = "{$root}/wireguard/.orbit.peer-transaction" ] && [ -f "{$root}/state/transaction-failure" ]; then exit 1; fi
            if [ "\${1:-}" = '-fT' ] && [ "\${2:-}" = '--' ]; then
                exec /bin/mv -f "\$3" "\$4"
            fi
            exec /bin/mv "\$@"
            SH,
    );
    remote_wireguard_peer_write_shim(
        root: $root,
        name: 'cp',
        body: <<<'SH'
            if [ "${1:-}" = '-a' ] && [ "${2:-}" = '--no-dereference' ] && [ "${3:-}" = '--' ]; then
                exec /bin/cp -a "$4" "$5"
            fi
            exec /bin/cp "$@"
            SH,
    );
    remote_wireguard_peer_write_shim(
        root: $root,
        name: 'systemctl',
        body: <<<SH
            printf '%s\n' "systemctl \$*" >> "{$root}/commands.log"
            action="\$1"
            case "\$action" in
                is-active)
                    state=\$(cat "{$root}/state/active")
                    printf '%s\n' "\$state"
                    [ "\$state" = 'active' ]
                    ;;
                is-enabled)
                    state=\$(cat "{$root}/state/enabled")
                    printf '%s\n' "\$state"
                    [ "\$state" = 'enabled' ]
                    ;;
                unmask)
                    printf 'disabled' > "{$root}/state/enabled"
                    ;;
                enable)
                    [ "\$(cat "{$root}/state/enabled")" != 'masked' ] || exit 1
                    case "\$(cat "{$root}/state/enabled")" in
                        generated|transient|alias|linked|linked-runtime) ;;
                        *)
                            if [ "\${2:-}" = '--runtime' ]; then
                                printf 'enabled-runtime' > "{$root}/state/enabled"
                            else
                                printf 'enabled' > "{$root}/state/enabled"
                            fi
                            ;;
                    esac
                    ;;
                disable)
                    case "$(cat "{$root}/state/enabled")" in
                        generated|transient|alias|linked|linked-runtime) ;;
                        *) printf 'disabled' > "{$root}/state/enabled" ;;
                    esac
                    ;;
                mask)
                    if [ "\${2:-}" = '--runtime' ]; then
                        printf 'masked-runtime' > "{$root}/state/enabled"
                    else
                        printf 'masked' > "{$root}/state/enabled"
                    fi
                    ;;
                start|restart)
                    [ ! -f "{$root}/state/restore-failure" ] || exit 1
                    printf 'active' > "{$root}/state/active"
                    ;;
                stop)
                    [ -f "{$root}/wireguard/orbit.conf" ] || exit 1
                    printf 'inactive' > "{$root}/state/active"
                    ;;
            esac
            SH,
    );
    remote_wireguard_peer_write_shim(
        root: $root,
        name: 'wg-quick',
        body: <<<SH
            printf '%s\n' "wg-quick \$*" >> "{$root}/commands.log"
            if [ "\$1" = 'strip' ]; then
                [ ! -f "{$root}/state/strip-failure" ] || exit 1
                /bin/cat -- "\$2"
            fi
            SH,
    );
    remote_wireguard_peer_write_shim(
        root: $root,
        name: 'wg',
        body: <<<SH
            printf '%s\n' "wg \$*" >> "{$root}/commands.log"
            if [ "\$1" = 'show' ] && [ -f "{$root}/state/late-failure" ]; then
                exit 1
            fi
            printf '%s\n' 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
            SH,
    );
    remote_wireguard_peer_write_shim(
        root: $root,
        name: 'ip',
        body: <<<SH
            printf '%s\n' "ip \$*" >> "{$root}/commands.log"
            printf '%s\n' '10.43.0.53 via 192.0.2.1 dev eth0 src 192.0.2.7'
            SH,
    );
    remote_wireguard_peer_write_shim(
        root: $root,
        name: 'resolvectl',
        body: <<<SH
            printf '%s\n' "resolvectl \$*" >> "{$root}/commands.log"
            if [ -f "{$root}/state/rollback-failure" ]; then
                exit 1
            fi
            SH,
    );
    remote_wireguard_peer_write_shim(
        root: $root,
        name: 'chown',
        body: <<<'SH'
            exit 0
            SH,
    );
    remote_wireguard_peer_write_shim(
        root: $root,
        name: 'flock',
        body: <<<'SH'
            exit 0
            SH,
    );
    remote_wireguard_peer_write_shim(
        root: $root,
        name: 'rm',
        body: <<<SH
            if [ -f "{$root}/state/commit-cleanup-failure" ]; then
                for argument in "\$@"; do
                    if [ "\$argument" = "{$root}/wireguard/.orbit.peer-transaction" ]; then
                        /bin/rm -f -- "{$root}/state/commit-cleanup-failure"
                        exit 1
                    fi
                done
            fi
            exec /bin/rm "\$@"
            SH,
    );

    $settings = new VpnSettings(app(SettingRepository::class));
    $settings->configure(
        subnet: '10.43.0.0/24',
        endpoint: '192.0.2.10:51820',
        dnsServer: $dnsServer ?? '10.43.0.53',
    );
    $gateway = Node::query()->create([
        'name' => 'gateway-peer-shell',
        'public_ssh_host' => '192.0.2.10',
        'wireguard_ip' => '10.43.0.1',
        'wireguard_public_key' => str_repeat(string: 'P', times: 43).'=',
    ]);
    $gateway->roles()->create(['role' => RoleName::Vpn]);
    $peer = Node::query()->create([
        'name' => 'peer-shell',
        'public_ssh_host' => '192.0.2.7',
        'wireguard_ip' => '10.43.0.7',
        'wireguard_public_key' => str_repeat(string: 'A', times: 43).'=',
        'tld' => $peerTld,
    ]);
    $gatewayPeers = new class implements GatewayPeerProjectionManager {
        public function converge(Node $node): void {}

        public function remove(Node $node): void {}

        public function restore(Node $node): void {}
    };
    $ssh = new class($root) implements SshExecutor {
        /** @var list<RemoteCommand> */
        public array $commands = [];

        public function __construct(
            private readonly string $root,
        ) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->commands[] = $command;
            $input = remote_wireguard_peer_rewrite_shell($command->input ?? '', $this->root);
            $bash = is_executable('/opt/homebrew/bin/bash') ? '/opt/homebrew/bin/bash' : '/bin/bash';
            $arguments = array_merge(
                [$bash, '-seu', '--'],
                array_slice(array: $command->arguments, offset: 4),
            );
            $process = proc_open(
                $arguments,
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $this->root,
                ['PATH' => "{$this->root}/bin:/usr/bin:/bin"],
            );

            if (! is_resource($process)) {
                throw new RuntimeException('Could not start the remote peer shell fixture.');
            }

            fwrite($pipes[0], $input);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            return new CommandResult(
                $exitCode,
                $stdout === false ? '' : $stdout,
                $stderr === false ? '' : $stderr,
                1,
                false,
            );
        }
    };
    $converger = new NativeWireGuardPeerConverger(
        configuration: new VpnConfigurationRepository($settings, $root),
        gatewayPeers: $gatewayPeers,
        ssh: $ssh,
    );
    $connection = new SshConnection(
        host: '192.0.2.7',
        user: 'orbit',
        port: 22,
        identityFile: '/tmp/key',
        knownHostsFile: '/tmp/known_hosts',
    );

    $converge = static function (bool $lateFailure) use ($root, $converger, $peer, $connection): ?\Throwable {
        if ($lateFailure) {
            file_put_contents(filename: $root.'/state/late-failure', data: '1');
        }

        try {
            $converger->converge($peer, $connection);
        } catch (\Throwable $throwable) {
            return $throwable;
        }

        return null;
    };

    /** @mago-expect lint:too-many-methods The transaction fixture exposes each public failure trigger and observable host state. */
    return new class(
        $root,
        $originalLiveContent,
        $originalDnsContent,
        $converger,
        $peer,
        $connection,
        $converge,
        $ssh,
    ) {
        /** @mago-expect lint:excessive-parameter-list The fixture retains every concrete dependency needed by its public lifecycle methods. */
        public function __construct(
            private readonly string $root,
            private readonly string $originalLiveContent,
            private readonly string $originalDnsContent,
            private readonly NativeWireGuardPeerConverger $converger,
            private readonly Node $peer,
            private readonly SshConnection $connection,
            private readonly \Closure $converge,
            private readonly object $ssh,
        ) {}

        public function converge(bool $lateFailure): array
        {
            $exception = ($this->converge)($lateFailure);

            return [
                'succeeded' => $exception === null,
                'stderr' => $exception?->getMessage() ?? '',
                ...$this->state(),
            ];
        }

        public function convergeRecoverably(?\Closure $completion = null): array
        {
            try {
                $this->converger->convergeRecoverably(
                    $this->peer,
                    $this->connection,
                    $completion ?? static function (): void {},
                );
                $exception = null;
            } catch (\Throwable $throwable) {
                $exception = $throwable;
            }

            return [
                'succeeded' => $exception === null,
                'exception' => $exception,
                'stderr' => $exception?->getMessage() ?? '',
                ...$this->state(),
            ];
        }

        public function failRollback(): void
        {
            file_put_contents(filename: $this->root.'/state/rollback-failure', data: '1');
        }

        public function failWireGuard(): void
        {
            file_put_contents(filename: $this->root.'/state/late-failure', data: '1');
        }

        public function failStrip(): void
        {
            file_put_contents(filename: $this->root.'/state/strip-failure', data: '1');
        }

        public function failTransactionPublication(): void
        {
            file_put_contents($this->root.'/state/transaction-failure', '1');
        }

        public function allowTransactionPublication(): void
        {
            new Filesystem()->delete($this->root.'/state/transaction-failure');
        }

        public function failCommitCleanup(): void
        {
            file_put_contents(filename: $this->root.'/state/commit-cleanup-failure', data: '1');
        }

        public function allowRollback(): void
        {
            $path = $this->root.'/state/rollback-failure';

            if (is_file($path)) {
                unlink($path);
            }
        }

        public function allowStrip(): void
        {
            new Filesystem()->delete($this->root.'/state/strip-failure');
        }

        public function failRestore(): void
        {
            file_put_contents(filename: $this->root.'/state/restore-failure', data: '1');
        }

        public function originalLive(): array
        {
            return $this->expectedFileState($this->originalLiveContent, 0o640);
        }

        public function originalDns(): array
        {
            return $this->expectedFileState($this->originalDnsContent, 0o644);
        }

        public function cleanup(): void
        {
            new Filesystem()->deleteDirectory($this->root);
        }

        public function root(): string
        {
            return $this->root;
        }

        public function converger(): NativeWireGuardPeerConverger
        {
            return $this->converger;
        }

        public function peer(): Node
        {
            return $this->peer;
        }

        public function connection(): SshConnection
        {
            return $this->connection;
        }

        public function state(): array
        {
            return [
                'live' => $this->fileState($this->root.'/wireguard/orbit.conf'),
                'dns' => $this->fileState($this->root.'/wireguard/orbit.dns-link'),
                'service_state' => [
                    trim((string) file_get_contents($this->root.'/state/active')),
                    trim((string) file_get_contents($this->root.'/state/enabled')),
                ],
                'command_log' => $this->commandLog(),
                'remote_arguments' => array_map(
                    static fn (RemoteCommand $command): array => $command->arguments,
                    $this->ssh->commands,
                ),
                'rollback_artifacts' => array_values(array_filter(
                    [
                        $this->root.'/wireguard/orbit-candidate.conf',
                        $this->root.'/wireguard/.orbit.conf.rollback',
                        $this->root.'/wireguard/.orbit.dns-link.candidate',
                        $this->root.'/wireguard/.orbit.dns-link.rollback',
                        $this->root.'/wireguard/.orbit.peer-transaction',
                    ],
                    static fn (string $path): bool => file_exists($path) || is_link($path),
                )),
            ];
        }

        private function expectedFileState(string $contents, int $mode): array
        {
            return [
                'contents' => $contents,
                'mode' => $mode,
                'owner' => posix_geteuid().':'.posix_getegid(),
            ];
        }

        private function fileState(string $path): ?array
        {
            if (! is_file($path)) {
                return null;
            }

            return [
                'contents' => (string) file_get_contents($path),
                'mode' => fileperms($path) & 0o777,
                'owner' => fileowner($path).':'.filegroup($path),
                ...(is_link($path) ? ['link_target' => readlink($path)] : []),
            ];
        }

        /** @return list<string> */
        private function commandLog(): array
        {
            if (! is_file($this->root.'/commands.log')) {
                return [];
            }

            return array_values(array_filter(
                array_map('trim', explode("\n", (string) file_get_contents($this->root.'/commands.log'))),
                static fn (string $line): bool => $line !== '',
            ));
        }
    };
}

function remote_wireguard_peer_write_shim(string $root, string $name, string $body): void
{
    file_put_contents("{$root}/bin/{$name}", "#!/bin/sh\n{$body}\n");
    chmod(filename: "{$root}/bin/{$name}", permissions: 0o700);
}

function remote_wireguard_peer_rewrite_shell(string $input, string $root): string
{
    return str_replace(
        [
            '/run/lock/orbit-wireguard-peer.lock',
            '/etc/wireguard/orbit-candidate.conf',
            '/etc/wireguard/.orbit.conf.rollback',
            '/etc/wireguard/.orbit.dns-link.candidate',
            '/etc/wireguard/.orbit.dns-link.rollback',
            '/etc/wireguard/.orbit.peer-transaction.candidate',
            '/etc/wireguard/.orbit.peer-transaction',
            '/etc/wireguard/orbit.dns-link',
            '/etc/wireguard/orbit.conf',
            '/etc/wireguard/orbit.public',
            '/etc/wireguard/orbit.key',
            '/etc/wireguard',
        ],
        [
            $root.'/state/orbit-wireguard-peer.lock',
            $root.'/wireguard/orbit-candidate.conf',
            $root.'/wireguard/.orbit.conf.rollback',
            $root.'/wireguard/.orbit.dns-link.candidate',
            $root.'/wireguard/.orbit.dns-link.rollback',
            $root.'/wireguard/.orbit.peer-transaction.candidate',
            $root.'/wireguard/.orbit.peer-transaction',
            $root.'/wireguard/orbit.dns-link',
            $root.'/wireguard/orbit.conf',
            $root.'/wireguard/orbit.public',
            $root.'/wireguard/orbit.key',
            $root.'/wireguard',
        ],
        $input,
    );
}
