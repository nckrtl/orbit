<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\RoleName;
use App\Domain\Settings\SettingRepository;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\WireGuard\NativeWireGuardPeerDnsRepairer;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

it('repairs live and persisted DNS repeatedly without restarting the tunnel', function (): void {
    $harness = wireguard_peer_dns_repair_harness();

    try {
        $first = $harness->repair();
        $second = $harness->repair();

        expect($first['exception'])
            ->toBeNull($first['exception']?->result?->stderr ?? '')
            ->and($second['exception'])
            ->toBeNull($second['exception']?->result?->stderr ?? '')
            ->and($second['live'])
            ->toBe($first['live'])
            ->toContain(
                'PrivateKey = stable-private-key',
                'Address = 10.43.0.7/24',
                'PostUp = resolvectl dns %i 10.43.0.53; resolvectl domain %i \\~.',
                "PreDown = resolvectl dns %i ''; resolvectl domain %i ''",
                'AllowedIPs = 10.43.0.0/24',
            )
            ->and($second['dns'])
            ->toBe("orbit\n10.43.0.53\n.\n")
            ->and($second['private_key'])
            ->toBe('stable-private-key-material')
            ->and($second['artifacts'])
            ->toBe([])
            ->and($second['commands'])
            ->toContain(
                'systemctl is-active --quiet wg-quick@orbit',
                'resolvectl dns orbit 10.43.0.53',
                'resolvectl domain orbit ~.',
            )
            ->not->toContain(
                'systemctl restart wg-quick@orbit',
                'systemctl stop wg-quick@orbit',
                'systemctl start wg-quick@orbit',
            )
            ->and($second['connections'])
            ->toHaveCount(2)
            ->and($second['connections'][0])
            ->toMatchArray([
                'host' => '10.43.0.7',
                'user' => 'orbit',
                'port' => 22,
                'identityFile' => '/gateway/ssh/id_ed25519',
                'knownHostsFile' => '/gateway/ssh/known_hosts',
            ]);
    } finally {
        $harness->cleanup();
    }
});

it('preserves suffix-only routing for an explicit underlay DNS override', function (): void {
    $harness = wireguard_peer_dns_repair_harness('192.0.2.53', 'custom.internal');

    try {
        $result = $harness->repair();

        expect($result['exception'])
            ->toBeNull($result['exception']?->result?->stderr ?? '')
            ->and($result['live'])
            ->not->toContain('PostUp = resolvectl', 'PreDown = resolvectl')
            ->toContain('PrivateKey = stable-private-key', 'AllowedIPs = 10.43.0.0/24')
            ->and($result['dns'])
            ->toBe("eth0\n192.0.2.53\norbit\ncustom.internal\n")
            ->and($result['commands'])
            ->toContain(
                'ip -o route get 192.0.2.53',
                'resolvectl revert orbit',
                'resolvectl dns eth0 192.0.2.53',
                'resolvectl domain eth0 ~orbit ~custom.internal',
            )
            ->and($result['artifacts'])
            ->toBe([]);
    } finally {
        $harness->cleanup();
    }
});

it('preserves suffix-only routing for an explicit in-VPN DNS override', function (): void {
    $harness = wireguard_peer_dns_repair_harness('10.43.0.53', 'custom.internal');

    try {
        $result = $harness->repair();

        expect($result['exception'])
            ->toBeNull($result['exception']?->result?->stderr ?? '')
            ->and($result['live'])
            ->toContain(
                'PostUp = resolvectl dns %i 10.43.0.53; resolvectl domain %i \\~orbit \\~custom.internal',
                "PreDown = resolvectl dns %i ''; resolvectl domain %i ''",
            )
            ->and($result['dns'])
            ->toBe("orbit\n10.43.0.53\norbit\ncustom.internal\n")
            ->and($result['commands'])
            ->toContain(
                'resolvectl dns orbit 10.43.0.53',
                'resolvectl domain orbit ~orbit ~custom.internal',
            )
            ->not->toContain('ip -o route get 10.43.0.53')
            ->and($result['artifacts'])
            ->toBe([]);
    } finally {
        $harness->cleanup();
    }
});

it('refuses contention and existing peer recovery without publication', function (string $state, string $errorCode): void {
    $harness = wireguard_peer_dns_repair_harness();

    try {
        $before = $harness->state();
        $state === 'busy' ? $harness->failLock() : $harness->addPeerRecovery();

        $result = $harness->repair();

        expect($result['exception'])
            ->toBeInstanceOf(NodeProvisioningException::class)
            ->and($result['exception']->errorCode)
            ->toBe($errorCode)
            ->and($result['live'])
            ->toBe($before['live'])
            ->and($result['dns'])
            ->toBe($before['dns'])
            ->and($result['commands'])
            ->not->toContain('resolvectl dns orbit 10.43.0.53');
    } finally {
        $harness->cleanup();
    }
})->with([
    'active peer operation' => ['busy', 'vpn.peer_dns_busy'],
    'pending peer recovery' => ['recovery', 'vpn.peer_recovery_pending'],
]);

it('rejects an invalid candidate before changing DNS state', function (): void {
    $harness = wireguard_peer_dns_repair_harness();

    try {
        $before = $harness->state();
        $harness->failCandidateValidation();

        $result = $harness->repair();

        expect($result['exception'])
            ->toBeInstanceOf(NodeProvisioningException::class)
            ->and($result['exception']->errorCode)
            ->toBe('vpn.peer_dns_candidate_invalid')
            ->and($result['live'])
            ->toBe($before['live'])
            ->and($result['dns'])
            ->toBe($before['dns'])
            ->and($result['artifacts'])
            ->toBe([])
            ->and($result['commands'])
            ->not->toContain('resolvectl dns orbit 10.43.0.53');
    } finally {
        $harness->cleanup();
    }
});

it('restores the exact preceding DNS state when live apply fails', function (): void {
    $harness = wireguard_peer_dns_repair_harness();

    try {
        $before = $harness->state();
        $harness->failLiveApply();

        $result = $harness->repair();

        expect($result['exception'])
            ->toBeInstanceOf(NodeProvisioningException::class)
            ->and($result['exception']->errorCode)
            ->toBe('vpn.peer_dns_apply_failed')
            ->and($result['live'])
            ->toBe($before['live'])
            ->and($result['dns'])
            ->toBe($before['dns'])
            ->and($result['private_key'])
            ->toBe($before['private_key'])
            ->and($result['artifacts'])
            ->toBe([])
            ->and($result['commands'])
            ->toContain(
                'resolvectl domain orbit ~.',
                'resolvectl dns orbit 10.43.0.53',
                'resolvectl domain orbit ~orbit',
            );
    } finally {
        $harness->cleanup();
    }
});

it('restores before applying a changed DNS link when state publication fails', function (): void {
    $harness = wireguard_peer_dns_repair_harness('192.0.2.53', 'custom.internal');

    try {
        $before = $harness->state();
        $harness->failDnsStatePublication();

        $result = $harness->repair();

        expect($result['exception'])
            ->toBeInstanceOf(NodeProvisioningException::class)
            ->and($result['exception']->errorCode)
            ->toBe('vpn.peer_dns_apply_failed')
            ->and($result['live'])
            ->toBe($before['live'])
            ->and($result['dns'])
            ->toBe($before['dns'])
            ->and($result['private_key'])
            ->toBe($before['private_key'])
            ->and($result['artifacts'])
            ->toBe([])
            ->and($result['commands'])
            ->toContain(
                'resolvectl revert orbit',
                'resolvectl dns orbit 10.43.0.53',
                'resolvectl domain orbit ~orbit',
            )
            ->not->toContain(
                'resolvectl dns eth0 192.0.2.53',
                'resolvectl domain eth0 ~orbit ~custom.internal',
            );
    } finally {
        $harness->cleanup();
    }
});

it('retains explicit recovery state and completes it on retry', function (): void {
    $harness = wireguard_peer_dns_repair_harness();

    try {
        $before = $harness->state();
        $harness->failLiveApply();
        $harness->failRecovery();

        $failed = $harness->repair();

        expect($failed['exception'])
            ->toBeInstanceOf(NodeProvisioningException::class)
            ->and($failed['exception']->errorCode)
            ->toBe('vpn.peer_dns_recovery_failed')
            ->and($failed['live'])
            ->toBe($before['live'])
            ->and($failed['dns'])
            ->toBe($before['dns'])
            ->and($failed['artifacts'])
            ->toContain(
                '.orbit.conf.rollback',
                '.orbit.dns-link.rollback',
                '.orbit.peer-transaction',
            );

        $harness->allowFailures();
        $retried = $harness->repair();

        expect($retried['exception'])
            ->toBeNull()
            ->and($retried['live'])
            ->toContain('resolvectl domain %i \\~.')
            ->and($retried['dns'])
            ->toBe("orbit\n10.43.0.53\n.\n")
            ->and($retried['artifacts'])
            ->toBe([]);
    } finally {
        $harness->cleanup();
    }
});

function wireguard_peer_dns_repair_harness(
    ?string $dnsServerOverride = null,
    ?string $tld = null,
): object {
    $root = sys_get_temp_dir().'/orbit-peer-dns-repair-'.Str::uuid();
    $filesystem = new Filesystem;
    $filesystem->makeDirectory("{$root}/wireguard", 0o700, true);
    $filesystem->makeDirectory("{$root}/bin", 0o700, true);
    $filesystem->makeDirectory("{$root}/state", 0o700, true);
    $filesystem->makeDirectory("{$root}/lock", 0o700, true);

    $originalLive = <<<'CONF'
        [Interface]
        PrivateKey = stable-private-key
        Address = 10.43.0.7/24
        PostUp = resolvectl dns %i 10.43.0.53; resolvectl domain %i \~orbit
        PreDown = resolvectl revert %i

        [Peer]
        PublicKey = stable-server-key
        Endpoint = 192.0.2.1:51820
        AllowedIPs = 10.43.0.0/24
        PersistentKeepalive = 25
        CONF;
    $originalLive .= "\n";
    $originalDns = "orbit\n10.43.0.53\norbit\n";
    file_put_contents("{$root}/wireguard/orbit.conf", $originalLive);
    file_put_contents("{$root}/wireguard/orbit.dns-link", $originalDns);
    file_put_contents("{$root}/wireguard/orbit.key", 'stable-private-key-material');
    file_put_contents("{$root}/wireguard/private.key", str_repeat(string: 'S', times: 43).'=');
    file_put_contents("{$root}/wireguard/public.key", str_repeat(string: 'P', times: 43).'=');

    wireguard_peer_dns_repair_shim($root, 'flock', <<<'SH'
        if [ -f "$ORBIT_TEST_ROOT/state/lock-failure" ]; then exit 1; fi
        exit 0
        SH);
    wireguard_peer_dns_repair_shim($root, 'systemctl', <<<'SH'
        printf '%s\n' "systemctl $*" >> "$ORBIT_TEST_ROOT/commands.log"
        if [ "${1:-}" = is-active ] && [ "${2:-}" = --quiet ] && [ "${3:-}" = wg-quick@orbit ]; then exit 0; fi
        exit 1
        SH);
    wireguard_peer_dns_repair_shim($root, 'wg', <<<'SH'
        printf '%s\n' "wg $*" >> "$ORBIT_TEST_ROOT/commands.log"
        if [ "$*" = 'show orbit public-key' ]; then
            printf '%s\n' 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
            exit 0
        fi
        exit 1
        SH);
    wireguard_peer_dns_repair_shim($root, 'wg-quick', <<<'SH'
        printf '%s\n' "wg-quick $*" >> "$ORBIT_TEST_ROOT/commands.log"
        if [ -f "$ORBIT_TEST_ROOT/state/candidate-failure" ]; then exit 1; fi
        [ "${1:-}" = strip ]
        SH);
    wireguard_peer_dns_repair_shim($root, 'ip', <<<'SH'
        printf '%s\n' "ip $*" >> "$ORBIT_TEST_ROOT/commands.log"
        if [ "${1:-}" = -o ] && [ "${2:-}" = route ] && [ "${3:-}" = get ]; then
            printf '%s dev eth0 src 192.0.2.7\n' "${4:-}"
            exit 0
        fi
        exit 1
        SH);
    wireguard_peer_dns_repair_shim($root, 'resolvectl', <<<'SH'
        printf '%s\n' "resolvectl $*" >> "$ORBIT_TEST_ROOT/commands.log"
        if [ -f "$ORBIT_TEST_ROOT/state/apply-failure" ] && [ "$*" = 'domain orbit ~.' ]; then exit 1; fi
        if [ -f "$ORBIT_TEST_ROOT/state/recovery-failure" ] && [ "$*" = 'domain orbit ~orbit' ]; then exit 1; fi
        exit 0
        SH);
    wireguard_peer_dns_repair_shim($root, 'chown', <<<'SH'
        exit 0
        SH);
    wireguard_peer_dns_repair_shim($root, 'mv', <<<'SH'
        if [ -f "$ORBIT_TEST_ROOT/state/dns-state-publication-failure" ] \
            && [ "${3:-}" = "$ORBIT_TEST_ROOT/wireguard/.orbit.dns-link.candidate" ] \
            && [ "${4:-}" = "$ORBIT_TEST_ROOT/wireguard/orbit.dns-link" ]; then
            exit 1
        fi
        exec /bin/mv "$@"
        SH);

    $settings = new VpnSettings(app(SettingRepository::class));
    $settings->configure(
        subnet: '10.43.0.0/24',
        endpoint: '192.0.2.1:51820',
        dnsServer: '10.43.0.53',
    );
    $gateway = Node::query()->create([
        'name' => 'gateway-dns-repair',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.43.0.1',
        'wireguard_public_key' => str_repeat(string: 'P', times: 43).'=',
    ]);
    $gateway->roles()->create(['role' => RoleName::Vpn, 'status' => LifecycleStatus::Active]);
    $peer = Node::query()->create([
        'name' => 'peer-dns-repair',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.7',
        'user' => 'orbit',
        'wireguard_ip' => '10.43.0.7',
        'wireguard_public_key' => str_repeat(string: 'A', times: 43).'=',
        'dns_server_override' => $dnsServerOverride,
        'tld' => $tld,
        'ssh_host_fingerprint' => 'SHA256:pinned',
    ]);
    $peer->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $ssh = new class($root) implements SshExecutor
    {
        /** @var list<SshConnection> */
        public array $connections = [];

        public function __construct(
            private readonly string $root,
        ) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->connections[] = $connection;
            $input = str_replace(
                ['/etc/wireguard', '/run/lock/orbit-wireguard-peer.lock'],
                ["{$this->root}/wireguard", "{$this->root}/lock/orbit-wireguard-peer.lock"],
                $command->input ?? '',
            );
            $process = proc_open(
                ['/bin/bash', '-seu', '--', ...array_slice($command->arguments, 4)],
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $this->root,
                [
                    'PATH' => "{$this->root}/bin:/usr/bin:/bin",
                    'ORBIT_TEST_ROOT' => $this->root,
                ],
            );

            if (! is_resource($process)) {
                throw new RuntimeException('Could not start the DNS repair shell fixture.');
            }

            $written = 0;
            while ($written < mb_strlen($input, '8bit')) {
                $bytes = @fwrite($pipes[0], substr($input, $written));

                if (! is_int($bytes) || $bytes === 0) {
                    break;
                }

                $written += $bytes;
            }
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
    $repairer = new NativeWireGuardPeerDnsRepairer(
        configuration: new VpnConfigurationRepository($settings, $root),
        ssh: $ssh,
        sshKeys: new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/gateway/ssh/id_ed25519';
            }

            public function publicKey(): string
            {
                return 'unused';
            }
        },
        knownHosts: new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/gateway/ssh/known_hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
    );

    return new class($root, $filesystem, $repairer, $peer, $ssh)
    {
        public function __construct(
            private readonly string $root,
            private readonly Filesystem $filesystem,
            private readonly NativeWireGuardPeerDnsRepairer $repairer,
            private readonly Node $peer,
            private readonly object $ssh,
        ) {}

        public function repair(): array
        {
            try {
                $this->repairer->repair($this->peer);
                $exception = null;
            } catch (NodeProvisioningException $failure) {
                $exception = $failure;
            }

            return ['exception' => $exception, ...$this->state()];
        }

        public function state(): array
        {
            return [
                'live' => (string) file_get_contents("{$this->root}/wireguard/orbit.conf"),
                'dns' => (string) file_get_contents("{$this->root}/wireguard/orbit.dns-link"),
                'private_key' => (string) file_get_contents("{$this->root}/wireguard/orbit.key"),
                'commands' => is_file("{$this->root}/commands.log")
                    ? array_values(array_filter(explode("\n", trim((string) file_get_contents("{$this->root}/commands.log")))))
                    : [],
                'connections' => array_map(
                    static fn (SshConnection $connection): array => (array) $connection,
                    $this->ssh->connections,
                ),
                'artifacts' => array_values(array_map(
                    basename(...),
                    glob("{$this->root}/wireguard/.orbit*") ?: [],
                )),
            ];
        }

        public function failLock(): void
        {
            file_put_contents("{$this->root}/state/lock-failure", '1');
        }

        public function addPeerRecovery(): void
        {
            file_put_contents("{$this->root}/wireguard/.orbit.peer-transaction", "active\nenabled\n1\n1\n");
            file_put_contents("{$this->root}/wireguard/.orbit.conf.rollback", 'peer backup');
            file_put_contents("{$this->root}/wireguard/.orbit.dns-link.rollback", 'peer dns backup');
        }

        public function failCandidateValidation(): void
        {
            file_put_contents("{$this->root}/state/candidate-failure", '1');
        }

        public function failLiveApply(): void
        {
            file_put_contents("{$this->root}/state/apply-failure", '1');
        }

        public function failDnsStatePublication(): void
        {
            file_put_contents("{$this->root}/state/dns-state-publication-failure", '1');
        }

        public function failRecovery(): void
        {
            file_put_contents("{$this->root}/state/recovery-failure", '1');
        }

        public function allowFailures(): void
        {
            $this->filesystem->delete([
                "{$this->root}/state/apply-failure",
                "{$this->root}/state/recovery-failure",
            ]);
        }

        public function cleanup(): void
        {
            $this->filesystem->deleteDirectory($this->root);
        }
    };
}

function wireguard_peer_dns_repair_shim(string $root, string $name, string $body): void
{
    $path = "{$root}/bin/{$name}";
    file_put_contents($path, "#!/bin/bash\nset -eu\n{$body}\n");
    chmod($path, 0o700);
}
