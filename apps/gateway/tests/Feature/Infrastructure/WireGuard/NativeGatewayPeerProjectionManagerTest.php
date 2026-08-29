<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Settings\SettingRepository;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\Files\ProtectedFileWriter;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\WireGuard\NativeGatewayPeerProjectionManager;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Infrastructure\WireGuard\WireGuardServerConfigRenderer;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

it('removes and restores only the selected peer in the serialized gateway projection', function (): void {
    $orbitHome = sys_get_temp_dir().'/orbit-vpn-projection-'.Str::uuid();
    mkdir(directory: $orbitHome.'/wireguard', permissions: 0o700, recursive: true);
    file_put_contents($orbitHome.'/wireguard/private.key', str_repeat(string: 'S', times: 43).'=');
    file_put_contents($orbitHome.'/wireguard/public.key', str_repeat(string: 'P', times: 43).'=');

    try {
        $gateway = Node::query()->create([
            'name' => 'gateway',
            'public_ssh_host' => '85.9.218.89',
            'wireguard_address' => '10.44.0.1',
            'wireguard_public_key' => str_repeat(string: 'P', times: 43).'=',
        ]);
        $gateway->roles()->create(['role' => RoleName::Vpn]);
        $removedPeer = Node::query()->create([
            'name' => 'removed-peer',
            'public_ssh_host' => '94.237.40.75',
            'wireguard_address' => '10.44.0.2',
            'wireguard_public_key' => str_repeat(string: 'A', times: 43).'=',
        ]);
        Node::query()->create([
            'name' => 'remaining-peer',
            'public_ssh_host' => '94.237.40.76',
            'wireguard_address' => '10.44.0.3',
            'wireguard_public_key' => str_repeat(string: 'B', times: 43).'=',
        ]);
        $settings = new VpnSettings(app(SettingRepository::class));
        $settings->configure(
            subnet: '10.44.0.0/24',
            endpoint: '85.9.218.89:51820',
            dnsServer: '10.44.0.1',
        );

        $processes = new class($orbitHome) implements ProcessRunner {
            /** @var list<ProcessInvocation> */
            public array $calls = [];

            public bool $observedProjectionLock = false;

            public function __construct(
                private readonly string $orbitHome,
            ) {}

            public function run(ProcessInvocation $invocation): CommandResult
            {
                $this->calls[] = $invocation;

                if (count($this->calls) === 1) {
                    $lock = fopen($this->orbitHome.'/locks/wireguard-server.lock', mode: 'c+');

                    if ($lock === false) {
                        throw new RuntimeException('Could not inspect the WireGuard projection lock.');
                    }

                    $acquired = flock($lock, LOCK_EX | LOCK_NB);
                    $this->observedProjectionLock = ! $acquired;

                    if ($acquired) {
                        flock($lock, LOCK_UN);
                    }

                    fclose($lock);
                }

                return new CommandResult(0, '', '', 1, false);
            }
        };
        $manager = new NativeGatewayPeerProjectionManager(
            configuration: new VpnConfigurationRepository($settings, $orbitHome),
            serverRenderer: new WireGuardServerConfigRenderer,
            files: new ProtectedFileWriter,
            processes: $processes,
            orbitHome: $orbitHome,
        );

        $manager->remove($removedPeer);

        expect(file_get_contents($orbitHome.'/generated/wireguard/orbit.conf'))
            ->not
            ->toContain('# removed-peer', 'AllowedIPs = 10.44.0.2/32')
            ->toContain('# remaining-peer', 'AllowedIPs = 10.44.0.3/32')
            ->and($processes->calls)
            ->toHaveCount(5)
            ->and($processes->observedProjectionLock)
            ->toBeTrue();

        $manager->restore($removedPeer);

        expect(file_get_contents($orbitHome.'/generated/wireguard/orbit.conf'))
            ->toContain(
                '# removed-peer',
                'AllowedIPs = 10.44.0.2/32',
                '# remaining-peer',
                'AllowedIPs = 10.44.0.3/32',
            )
            ->and($processes->calls)
            ->toHaveCount(10)
            ->and($processes->calls[9]->input)
            ->toContain(
                'systemctl is-active --quiet wg-quick@orbit',
                'systemctl is-enabled --quiet wg-quick@orbit',
                'mktemp /etc/wireguard/orbit-wireguard.XXXXXX',
                'wg syncconf orbit "$runtime_config"',
                'systemctl start wg-quick@orbit',
            );
        expect($processes->calls[9]->input)->not->toContain('/run/orbit-wireguard');
    } finally {
        new Filesystem()->deleteDirectory($orbitHome);
    }
});

it('syncs the active enabled service without a normal restart', function (): void {
    $harness = gateway_peer_projection_harness(active: true, enabled: true);

    try {
        $result = $harness->converge();

        expect($result['succeeded'])
            ->toBeTrue($result['stderr'])
            ->and($result['live'])
            ->toBe($result['expected_live'])
            ->and($result['backup_exists'])
            ->toBeFalse()
            ->and($result['runtime_exists'])
            ->toBeFalse()
            ->and($result['command_log'])
            ->toBe([
                'systemctl is-active --quiet wg-quick@orbit',
                'systemctl is-enabled --quiet wg-quick@orbit',
                'systemctl enable wg-quick@orbit',
                'mktemp '.$harness->runtimeTemplate(),
                'chmod 0600 '.$harness->runtimePath(),
                'wg-quick strip '.$harness->livePath(),
                'wg syncconf orbit '.$harness->runtimePath(),
                'systemctl is-active --quiet wg-quick@orbit',
                'systemctl is-enabled --quiet wg-quick@orbit',
            ])
            ->and($result['command_log'])
            ->not->toContain('systemctl start wg-quick@orbit', 'systemctl restart wg-quick@orbit')->and(
                $result['logged_argv'],
            )
            ->not->toContain($harness->secret());
    } finally {
        $harness->cleanup();
    }
});

it('enables and starts the service when it was inactive and disabled', function (): void {
    $harness = gateway_peer_projection_harness(active: false, enabled: false);

    try {
        $result = $harness->converge();

        expect($result['succeeded'])
            ->toBeTrue($result['stderr'])
            ->and($result['live'])
            ->toBe($result['expected_live'])
            ->and($result['state'])
            ->toBe(['active', 'enabled'])
            ->and($result['backup_exists'])
            ->toBeFalse()
            ->and($result['runtime_exists'])
            ->toBeFalse()
            ->and($result['command_log'])
            ->toBe([
                'systemctl is-active --quiet wg-quick@orbit',
                'systemctl is-enabled --quiet wg-quick@orbit',
                'systemctl enable wg-quick@orbit',
                'mktemp '.$harness->runtimeTemplate(),
                'chmod 0600 '.$harness->runtimePath(),
                'systemctl start wg-quick@orbit',
                'systemctl is-active --quiet wg-quick@orbit',
                'systemctl is-enabled --quiet wg-quick@orbit',
            ])
            ->and($result['command_log'])
            ->not->toContain('wg syncconf orbit '.$harness->runtimePath(), 'systemctl restart wg-quick@orbit')->and(
                $result['logged_argv'],
            )
            ->not->toContain($harness->secret());
    } finally {
        $harness->cleanup();
    }
});

it('restores the old active enabled config when syncconf fails', function (): void {
    $harness = gateway_peer_projection_harness(active: true, enabled: true, failure: 'sync');

    try {
        $result = $harness->converge();

        expect($result['succeeded'])
            ->toBeFalse()
            ->and($result['live'])
            ->toBe($harness->originalLiveConfig())
            ->and($result['state'])
            ->toBe(['active', 'enabled'])
            ->and($result['backup_exists'])
            ->toBeFalse()
            ->and($result['runtime_exists'])
            ->toBeFalse()
            ->and($result['command_log'])
            ->toBe([
                'systemctl is-active --quiet wg-quick@orbit',
                'systemctl is-enabled --quiet wg-quick@orbit',
                'systemctl enable wg-quick@orbit',
                'mktemp '.$harness->runtimeTemplate(),
                'chmod 0600 '.$harness->runtimePath(),
                'wg-quick strip '.$harness->livePath(),
                'wg syncconf orbit '.$harness->runtimePath(),
                'wg-quick strip '.$harness->livePath(),
                'wg syncconf orbit '.$harness->runtimePath(),
                'systemctl enable wg-quick@orbit',
            ])
            ->and($result['command_log'])
            ->not->toContain('systemctl restart wg-quick@orbit', 'systemctl start wg-quick@orbit')->and(
                $result['logged_argv'],
            )
            ->not->toContain($harness->secret());
    } finally {
        $harness->cleanup();
    }
});

it('restores the old inactive disabled config when start fails', function (): void {
    $harness = gateway_peer_projection_harness(active: false, enabled: false, failure: 'start');

    try {
        $result = $harness->converge();

        expect($result['succeeded'])
            ->toBeFalse()
            ->and($result['live'])
            ->toBe($harness->originalLiveConfig())
            ->and($result['state'])
            ->toBe(['inactive', 'disabled'])
            ->and($result['backup_exists'])
            ->toBeFalse()
            ->and($result['runtime_exists'])
            ->toBeFalse()
            ->and($result['command_log'])
            ->toBe([
                'systemctl is-active --quiet wg-quick@orbit',
                'systemctl is-enabled --quiet wg-quick@orbit',
                'systemctl enable wg-quick@orbit',
                'mktemp '.$harness->runtimeTemplate(),
                'chmod 0600 '.$harness->runtimePath(),
                'systemctl start wg-quick@orbit',
                'systemctl stop wg-quick@orbit',
                'systemctl disable wg-quick@orbit',
            ])
            ->and($result['command_log'])
            ->not->toContain('systemctl restart wg-quick@orbit', 'wg syncconf orbit '.$harness->runtimePath())->and(
                $result['logged_argv'],
            )
            ->not->toContain($harness->secret());
    } finally {
        $harness->cleanup();
    }
});

it('restores the old file and state when mktemp fails', function (): void {
    $harness = gateway_peer_projection_harness(active: true, enabled: true, failure: 'mktemp');

    try {
        $result = $harness->converge();

        expect($result['succeeded'])
            ->toBeFalse()
            ->and($result['live'])
            ->toBe($harness->originalLiveConfig())
            ->and($result['state'])
            ->toBe(['active', 'enabled'])
            ->and($result['backup_exists'])
            ->toBeFalse()
            ->and($result['runtime_exists'])
            ->toBeFalse()
            ->and($result['command_log'])
            ->toBe([
                'systemctl is-active --quiet wg-quick@orbit',
                'systemctl is-enabled --quiet wg-quick@orbit',
                'systemctl enable wg-quick@orbit',
                'mktemp '.$harness->runtimeTemplate(),
                'systemctl enable wg-quick@orbit',
            ])
            ->and($result['command_log'])
            ->not->toContain(
                'wg-quick strip '.$harness->livePath(),
                'wg syncconf orbit '.$harness->runtimePath(),
                'systemctl start wg-quick@orbit',
                'systemctl restart wg-quick@orbit',
            )->and($result['logged_argv'])
            ->not->toContain($harness->secret());
    } finally {
        $harness->cleanup();
    }
});

it('deletes the insecure temp and never writes stripped secret bytes when chmod fails', function (): void {
    $harness = gateway_peer_projection_harness(active: true, enabled: true, failure: 'chmod');

    try {
        $result = $harness->converge();

        expect($result['succeeded'])
            ->toBeFalse()
            ->and($result['live'])
            ->toBe($harness->originalLiveConfig())
            ->and($result['state'])
            ->toBe(['active', 'enabled'])
            ->and($result['backup_exists'])
            ->toBeFalse()
            ->and($result['runtime_exists'])
            ->toBeFalse()
            ->and($result['runtime_contents'])
            ->toBeEmpty()
            ->and($result['command_log'])
            ->toBe([
                'systemctl is-active --quiet wg-quick@orbit',
                'systemctl is-enabled --quiet wg-quick@orbit',
                'systemctl enable wg-quick@orbit',
                'mktemp '.$harness->runtimeTemplate(),
                'chmod 0600 '.$harness->runtimePath(),
                'systemctl enable wg-quick@orbit',
            ])
            ->and($result['command_log'])
            ->not->toContain('wg-quick strip '.$harness->livePath(), 'wg syncconf orbit '.$harness->runtimePath())->and(
                $result['logged_argv'],
            )
            ->not->toContain($harness->secret());
    } finally {
        $harness->cleanup();
    }
});

/**
 * @mago-expect lint:cyclomatic-complexity The harness models independent service and command failure states.
 * @mago-expect lint:no-boolean-flag-parameter The flags set the initial systemd fixture state.
 */
function gateway_peer_projection_harness(bool $active, bool $enabled, ?string $failure = null): object
{
    $root = sys_get_temp_dir().'/orbit-wireguard-shell-'.Str::uuid();
    $filesystem = new Filesystem;
    $filesystem->makeDirectory("{$root}/wireguard", 0o700, true);
    $filesystem->makeDirectory("{$root}/bin", 0o700, true);
    $filesystem->makeDirectory("{$root}/state", 0o700, true);
    file_put_contents("{$root}/state/active", $active ? 'active' : 'inactive');
    file_put_contents("{$root}/state/enabled", $enabled ? 'enabled' : 'disabled');

    $secret = base64_encode(str_repeat(string: 'K', times: 32));
    $originalLiveConfig = "[Interface]\nPrivateKey = {$secret}\nAddress = 10.45.0.1/24\n";
    file_put_contents("{$root}/wireguard/live.conf", $originalLiveConfig);
    file_put_contents("{$root}/wireguard/backup.conf", $originalLiveConfig);
    file_put_contents("{$root}/wireguard/private.key", str_repeat(string: 'S', times: 43).'=');
    file_put_contents("{$root}/wireguard/public.key", str_repeat(string: 'P', times: 43).'=');

    gateway_peer_projection_write_shim(
        root: $root,
        name: 'systemctl',
        body: <<<SH
            printf '%s\n' "systemctl \$*" >> "{$root}/commands.log"
            action="\$1"
            case "\$action" in
                is-active)
                    [ "\$(cat "{$root}/state/active")" = 'active' ]
                    ;;
                is-enabled)
                    [ "\$(cat "{$root}/state/enabled")" = 'enabled' ]
                    ;;
                enable)
                    printf 'enabled' > "{$root}/state/enabled"
                    ;;
                disable)
                    printf 'disabled' > "{$root}/state/enabled"
                    ;;
                start)
                    if [ "{$failure}" = 'start' ]; then
                        exit 1
                    fi
                    printf 'active' > "{$root}/state/active"
                    ;;
                stop)
                    printf 'inactive' > "{$root}/state/active"
                    ;;
                restart)
                    printf 'active' > "{$root}/state/active"
                    ;;
            esac
            SH,
    );
    gateway_peer_projection_write_shim(
        root: $root,
        name: 'wg-quick',
        body: <<<SH
            printf '%s\n' "wg-quick \$*" >> "{$root}/commands.log"
            if [ "\$1" = 'strip' ]; then
                /usr/bin/cat -- "\$2"
                exit 0
            fi
            exit 0
            SH,
    );
    gateway_peer_projection_write_shim(
        root: $root,
        name: 'wg',
        body: <<<SH
            printf '%s\n' "wg \$*" >> "{$root}/commands.log"
            if [ "{$failure}" = 'sync' ] && [ ! -f "{$root}/state/wg_failed_once" ]; then
                : > "{$root}/state/wg_failed_once"
                exit 1
            fi
            exit 0
            SH,
    );
    gateway_peer_projection_write_shim(
        root: $root,
        name: 'mktemp',
        body: <<<SH
            printf '%s\n' "mktemp \$*" >> "{$root}/commands.log"
            if [ "{$failure}" = 'mktemp' ]; then
                exit 1
            fi
            runtime_path="{$root}/state/runtime.conf"
            : > "\$runtime_path"
            printf '%s\n' "\$runtime_path"
            SH,
    );
    gateway_peer_projection_write_shim(
        root: $root,
        name: 'chmod',
        body: <<<SH
            printf '%s\n' "chmod \$*" >> "{$root}/commands.log"
            if [ "{$failure}" = 'chmod' ] && [ "\$1" = '0600' ] && [ "\$2" = "{$root}/state/runtime.conf" ]; then
                exit 1
            fi
            exec /usr/bin/chmod "\$@"
            SH,
    );

    $settings = new VpnSettings(app(SettingRepository::class));
    $settings->configure(
        subnet: '10.45.0.0/24',
        endpoint: '85.9.218.90:51820',
        dnsServer: '10.45.0.1',
    );

    $node = Node::query()->create([
        'name' => 'gateway-shell',
        'public_ssh_host' => '85.9.218.90',
        'wireguard_address' => '10.45.0.1',
        'wireguard_public_key' => str_repeat(string: 'G', times: 43).'=',
    ]);
    $node->roles()->create(['role' => RoleName::Vpn]);

    $processes = new class($root) implements ProcessRunner {
        /** @var list<ProcessInvocation> */
        public array $calls = [];

        public function __construct(
            private readonly string $root,
        ) {}

        /** @mago-expect lint:halstead The fake preserves the complete host transaction boundary. */
        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->calls[] = $invocation;

            if (
                $invocation->arguments === [
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
                    $this->root.'/generated/wireguard/orbit.conf',
                    '/etc/wireguard/orbit-candidate.conf',
                ]
            ) {
                copy(
                    $this->root.'/generated/wireguard/orbit.conf',
                    $this->root.'/wireguard/candidate.conf',
                );

                return new CommandResult(0, '', '', 1, false);
            }

            if ($invocation->arguments === ['sudo', 'wg-quick', 'strip', '/etc/wireguard/orbit-candidate.conf']) {
                return new CommandResult(0, '', '', 1, false);
            }

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
                copy($this->root.'/wireguard/candidate.conf', $this->root.'/wireguard/live.conf');

                return new CommandResult(0, '', '', 1, false);
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
                if (is_file($this->root.'/wireguard/candidate.conf')) {
                    unlink($this->root.'/wireguard/candidate.conf');
                }
                if (is_file($this->root.'/wireguard/backup.conf')) {
                    unlink($this->root.'/wireguard/backup.conf');
                }

                return new CommandResult(0, '', '', 1, false);
            }

            if ($invocation->arguments === ['sudo', 'rm', '-f', '--', '/etc/wireguard/orbit-candidate.conf']) {
                if (is_file($this->root.'/wireguard/candidate.conf')) {
                    unlink($this->root.'/wireguard/candidate.conf');
                }

                return new CommandResult(0, '', '', 1, false);
            }

            if ($invocation->arguments === ['sudo', 'bash', '-seu']) {
                $input = gateway_peer_projection_rewrite_shell($invocation->input ?? '', $this->root);
                $process = proc_open(
                    ['/bin/bash', '-seu'],
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
                    throw new RuntimeException('Could not start the activation shell fixture.');
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

            return new CommandResult(0, '', '', 1, false);
        }
    };

    $manager = new NativeGatewayPeerProjectionManager(
        configuration: new VpnConfigurationRepository($settings, $root),
        serverRenderer: new WireGuardServerConfigRenderer,
        files: new ProtectedFileWriter,
        processes: $processes,
        orbitHome: $root,
    );

    $converge = static fn () => $manager->converge($node);

    return new class($root, $secret, $originalLiveConfig, $converge, $processes) {
        public function __construct(
            private readonly string $root,
            #[\SensitiveParameter]
            private readonly string $secret,
            private readonly string $originalLiveConfig,
            private readonly \Closure $converge,
            private readonly object $processes,
        ) {}

        public function converge(): array
        {
            $exception = null;

            try {
                ($this->converge)();
            } catch (\Throwable $throwable) {
                $exception = $throwable;
            }

            $runtimePath = $this->runtimePath();

            return [
                'succeeded' => $exception === null,
                'stderr' => $exception?->getMessage() ?? '',
                'live' => file_get_contents($this->livePath()),
                'expected_live' => is_file($this->root.'/generated/wireguard/orbit.conf')
                    ? (string) file_get_contents($this->root.'/generated/wireguard/orbit.conf')
                    : '',
                'state' => [
                    trim((string) file_get_contents($this->root.'/state/active')),
                    trim((string) file_get_contents($this->root.'/state/enabled')),
                ],
                'command_log' => $this->commandLog(),
                'logged_argv' => $this->loggedArguments(),
                'backup_exists' => is_file($this->backupPath()),
                'runtime_exists' => is_file($runtimePath),
                'runtime_contents' => is_file($runtimePath)
                    ? (string) file_get_contents($runtimePath)
                    : '',
            ];
        }

        public function livePath(): string
        {
            return $this->root.'/wireguard/live.conf';
        }

        public function backupPath(): string
        {
            return $this->root.'/wireguard/backup.conf';
        }

        public function runtimePath(): string
        {
            return $this->root.'/state/runtime.conf';
        }

        public function runtimeTemplate(): string
        {
            return $this->root.'/state/runtime.XXXXXX';
        }

        public function secret(): string
        {
            return $this->secret;
        }

        public function originalLiveConfig(): string
        {
            return $this->originalLiveConfig;
        }

        public function cleanup(): void
        {
            new Filesystem()->deleteDirectory($this->root);
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

        private function loggedArguments(): string
        {
            $parts = [];

            foreach ($this->processes->calls as $call) {
                $parts[] = implode(' ', $call->arguments);
            }

            return implode("\n", $parts);
        }
    };
}

function gateway_peer_projection_write_shim(string $root, string $name, string $body): void
{
    file_put_contents("{$root}/bin/{$name}", "#!/bin/sh\n{$body}\n");
    chmod(filename: "{$root}/bin/{$name}", permissions: 0o700);
}

function gateway_peer_projection_rewrite_shell(string $input, string $root): string
{
    return str_replace(
        [
            '/etc/wireguard/orbit.conf',
            '/etc/wireguard/.orbit.conf.rollback',
            '/etc/wireguard/orbit-wireguard.XXXXXX',
        ],
        [
            $root.'/wireguard/live.conf',
            $root.'/wireguard/backup.conf',
            $root.'/state/runtime.XXXXXX',
        ],
        $input,
    );
}
