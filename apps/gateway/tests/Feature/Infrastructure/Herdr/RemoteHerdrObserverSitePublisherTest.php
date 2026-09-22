<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Herdr\ObservationGrantClaims;
use App\Domain\Herdr\ObservationGrantSigner;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Herdr\RemoteHerdrObserverSitePublisher;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\HerdrSession;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Tests\Support\AppDevFakeSshExecutor;

it('publishes a hardened receive-only Herdr observer service', function (): void {
    $transport = new AppDevFakeSshExecutor;
    $node = herdr_publisher_node();
    $session = new HerdrSession([
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'observer_port' => 7411,
        'observer_hostname' => 'commander-tasks.herdr.beast.test',
    ]);
    $session->id = 12;
    $session->setRelation('node', $node);
    $publisher = new RemoteHerdrObserverSitePublisher(
        herdr_publisher_ssh($transport),
        new class implements LeafCertificateSigner
        {
            public function sign(string $hostname, string $certificateRequest): string
            {
                return 'PUBLIC CERTIFICATE';
            }

            public function rootCertificate(): string
            {
                return 'ROOT CERTIFICATE';
            }
        },
        herdr_observer_signer(),
    );

    $publisher->publish($session, $node, 'https://commander-tasks.herdr.beast.test { reverse_proxy 127.0.0.1:7411 }');

    $command = $transport->commands[0];
    $program = stream_get_contents($command->protectedInput?->stream());
    preg_match_all("/printf '%s' '([^']+)'/", (string) $program, $encoded);
    $observer = (string) base64_decode($encoded[1][0], true);
    $unit = (string) base64_decode($encoded[1][4], true);

    expect(herdr_publisher_shell_exit((string) $program))->toBe(0);
    expect(array_slice($command->arguments, 0, 6))
        ->toBe(['sudo', 'bash', '-seu', '--', 'commander-tasks', 'publish'])
        ->and($command->arguments[6])->toMatch('/\Aherdr-12-[a-f0-9]{16}\z/D')
        ->and(array_slice($command->arguments, 7))
        ->toBe([
            '/etc/orbit/herdr',
            '/var/lib/orbit/herdr-observer',
            '/etc/systemd/system',
            '/etc/caddy/orbit-versions',
            '/etc/caddy/Caddyfile',
            '/run/lock/orbit/caddy.lock',
            '/run',
            'root',
            'root',
            'caddy',
            'caddy',
            '/home/linuxbrew/.linuxbrew/bin/caddy',
            '/usr/bin/php',
            '/home/linuxbrew/.linuxbrew/bin/herdr',
        ])
        ->and($command->input)->toBeNull()
        ->and(str_contains((string) $program, 'unit=$systemd_directory/$unit_name'))->toBeTrue()
        ->and(str_contains((string) $program, 'state_directory=$state_root/$session'))->toBeTrue()
        ->and($program)->toContain('umask 0077')
        ->and($program)->toContain('lock=$9')
        ->and($program)->toContain('source_main=$(readlink -f "$live_caddyfile")')
        ->and($program)->toContain('legacy_fragment=$versions/current/fragments/$owned_fragment')
        ->and(strpos((string) $program, 'trap \'rm -rf -- "$work"\' EXIT'))
        ->toBeLessThan(strpos((string) $program, 'cp -a -- "$source_main" "$previous_main"'))
        ->and($program)->toContain('systemd-analyze verify "$work/$unit_name"')
        ->and($program)->toContain('install -o "$root_owner" -g "$caddy_group" -m 0640 -- "$work/key.pem"')
        ->and($program)->toContain('rollback()')
        ->and($program)->toContain('systemctl reload-or-restart "$caddy_service"')
        ->and(str_contains((string) $program, 'systemctl restart "$unit_name"'))->toBeTrue()
        ->and($unit)->toContain('ProtectSystem=strict')
        ->and($unit)->toContain('NoNewPrivileges=true')
        ->and($unit)->toContain('X-Orbit-Herdr-Session=commander-tasks')
        ->and($unit)->toContain('RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6')
        ->and($observer)->toContain("'terminal'")
        ->and($observer)->toContain("'observe'")
        ->and(str_contains((string) $program, 'PRIVATE SIGNING KEY'))->toBeFalse()
        ->and(str_contains((string) $program, 'orbit-grant-token'))->toBeFalse();
});

it('retracts only the owned Herdr observer service and files', function (): void {
    $transport = new AppDevFakeSshExecutor;
    $node = herdr_publisher_node();
    $session = new HerdrSession([
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'observer_port' => 7411,
        'observer_hostname' => 'commander-tasks.herdr.beast.test',
    ]);
    $session->id = 12;
    $session->setRelation('node', $node);
    $publisher = new RemoteHerdrObserverSitePublisher(
        herdr_publisher_ssh($transport),
        new class implements LeafCertificateSigner
        {
            public function sign(string $hostname, string $certificateRequest): string
            {
                return 'PUBLIC CERTIFICATE';
            }

            public function rootCertificate(): string
            {
                return 'ROOT CERTIFICATE';
            }
        },
        herdr_observer_signer(),
    );

    $publisher->retract($session, $node);

    $command = $transport->commands[0];
    $program = stream_get_contents($command->protectedInput?->stream());
    expect(array_slice($command->arguments, 0, 6))
        ->toBe(['sudo', 'bash', '-seu', '--', 'commander-tasks', 'retract'])
        ->and($command->arguments[6])->toMatch('/\Aherdr-12-[a-f0-9]{16}\z/D')
        ->and($program)->toContain('systemctl disable --now "$unit_name"')
        ->and($program)->toContain('rm -f -- "$unit"')
        ->and($program)->toContain('rm -rf -- "$directory"')
        ->and($program)->toContain('rm -f -- "$legacy_fragment"')
        ->and($program)->not->toContain('systemctl disable --now "$unit_name" >/dev/null 2>&1 || true')
        ->and($program)->not->toContain('rm -f -- "$state_directory/nonces.json"');
});

it('publishes legacy state and rolls back a failed retract in a controlled host filesystem', function (): void {
    $harness = herdr_publisher_harness();
    $transport = new AppDevFakeSshExecutor;
    $node = herdr_publisher_node();
    $node->user = $harness['user'];
    $session = new HerdrSession([
        'session' => 'commander-tasks',
        'user' => $harness['user'],
        'observer_port' => 7411,
        'observer_hostname' => 'commander-tasks.herdr.beast.test',
    ]);
    $session->id = 12;
    $session->setRelation('node', $node);
    $publisher = new RemoteHerdrObserverSitePublisher(
        ssh: herdr_publisher_ssh($transport),
        certificates: new HerdrPublisherTestCertificateSigner,
        grants: herdr_observer_signer(),
        configurationRoot: $harness['configuration_root'],
        stateRoot: $harness['state_root'],
        systemdDirectory: $harness['systemd_directory'],
        versionsDirectory: $harness['versions'],
        liveCaddyfilePath: $harness['live_caddyfile'],
        lockPath: $harness['lock'],
        temporaryDirectory: $harness['temporary_directory'],
        rootOwner: $harness['user'],
        rootGroup: $harness['group'],
        caddyGroup: $harness['group'],
        caddyServiceName: 'caddy',
        preferredCaddyExecutable: $harness['caddy'],
        phpExecutable: PHP_BINARY,
        herdrExecutable: $harness['herdr'],
    );

    try {
        $publisher->publish($session, $node, '# Managed by Orbit: herdr-observer'.PHP_EOL.'https://observer.test {}');
        $published = herdr_publisher_run($transport->commands[0], $harness);

        expect($published['exit'])
            ->toBe(0, $published['stderr'])
            ->and(is_link($harness['live_caddyfile']))
            ->toBeTrue()
            ->and(readlink($harness['live_caddyfile']))
            ->toContain('/orbit-versions/herdr-12-')
            ->and(file_get_contents($harness['configuration_root'].'/commander-tasks/.orbit-owner'))
            ->toBe('orbit-herdr-observer:commander-tasks')
            ->and(fileperms($harness['configuration_root'].'/commander-tasks/key.pem') & 0o777)
            ->toBe(0o640)
            ->and(filegroup($harness['configuration_root'].'/commander-tasks/key.pem'))
            ->toBe(posix_getegid())
            ->and(file_exists($harness['legacy_fragment']))
            ->toBeFalse()
            ->and(file_get_contents($harness['nonce_store']))
            ->toBe('{"used":4102444800}')
            ->and(glob($harness['temporary_directory'].'/orbit-herdr-observer-*'))
            ->toBe([]);

        $publishedTarget = readlink($harness['live_caddyfile']);
        $unit = $harness['systemd_directory'].'/orbit-herdr-observer-commander-tasks.service';
        file_put_contents($harness['systemctl_state'].'/fail-disable-once', '1');
        $publisher->retract($session, $node);
        $failedRetract = herdr_publisher_run($transport->commands[1], $harness);

        expect($failedRetract['exit'])
            ->not->toBe(0)
            ->and(readlink($harness['live_caddyfile']))
            ->toBe($publishedTarget)
            ->and(file_exists($unit))
            ->toBeTrue()
            ->and(file_exists($harness['configuration_root'].'/commander-tasks/observer.php'))
            ->toBeTrue()
            ->and(file_exists($harness['systemctl_state'].'/active-orbit-herdr-observer-commander-tasks.service'))
            ->toBeTrue()
            ->and(file_get_contents($harness['nonce_store']))
            ->toBe('{"used":4102444800}')
            ->and(glob($harness['temporary_directory'].'/orbit-herdr-observer-*'))
            ->toBe([]);

        $publisher->retract($session, $node);
        $retracted = herdr_publisher_run($transport->commands[2], $harness);
        $liveMain = (string) readlink($harness['live_caddyfile']);
        $liveFragments = dirname($liveMain).'/fragments';

        expect($retracted['exit'])
            ->toBe(0, $retracted['stderr'])
            ->and(file_exists($harness['configuration_root'].'/commander-tasks'))
            ->toBeFalse()
            ->and(file_exists($unit))
            ->toBeFalse()
            ->and(file_exists($liveFragments.'/unrelated.caddy'))
            ->toBeTrue()
            ->and(file_exists($liveFragments.'/herdr-commander-tasks.caddy'))
            ->toBeFalse()
            ->and(file_get_contents($harness['nonce_store']))
            ->toBe('{"used":4102444800}')
            ->and(glob($harness['temporary_directory'].'/orbit-herdr-observer-*'))
            ->toBe([]);
    } finally {
        new Filesystem()->deleteDirectory($harness['root']);
    }
});

function herdr_observer_signer(): ObservationGrantSigner
{
    return new class implements ObservationGrantSigner
    {
        public function sign(ObservationGrantClaims $claims): string
        {
            return 'token';
        }

        public function verify(string $token): ObservationGrantClaims
        {
            throw new LogicException('Not used.');
        }

        public function jwks(): array
        {
            return ['keys' => [[
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'kid' => 'public-key',
                'n' => 'public-modulus',
                'e' => 'AQAB',
            ]]];
        }
    };
}

function herdr_publisher_node(): Node
{
    return new Node([
        'name' => 'beast',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.20',
        'wireguard_ip' => '10.44.0.8',
        'user' => 'nckrtl',
    ]);
}

function herdr_publisher_ssh(AppDevFakeSshExecutor $transport): AppDevSshExecutor
{
    return new AppDevSshExecutor(
        $transport,
        new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/home/orbit/.orbit/ssh/id_ed25519';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 AAAA';
            }
        },
        new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/home/orbit/.orbit/ssh/known_hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
    );
}

function herdr_publisher_shell_exit(string $program): int
{
    $process = proc_open(
        ['/usr/bin/bash', '-n'],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
    );

    if (! is_resource($process)) {
        return 1;
    }

    fwrite($pipes[0], $program);
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    return proc_close($process);
}

/** @return array<string, string> */
function herdr_publisher_harness(): array
{
    $root = sys_get_temp_dir().'/orbit-herdr-publisher-'.bin2hex(random_bytes(8));
    $user = posix_getpwuid(posix_geteuid())['name'];
    $group = posix_getgrgid(posix_getegid())['name'];
    $configurationRoot = $root.'/etc/orbit/herdr';
    $stateRoot = $root.'/var/lib/orbit/herdr-observer';
    $systemdDirectory = $root.'/etc/systemd/system';
    $versions = $root.'/etc/caddy/orbit-versions';
    $liveCaddyfile = $root.'/etc/caddy/Caddyfile';
    $temporaryDirectory = $root.'/run';
    $lock = $temporaryDirectory.'/lock/orbit/caddy.lock';
    $systemctlState = $root.'/systemctl-state';
    $bin = $root.'/bin';
    $versionOne = $versions.'/version-one';
    $legacyFragment = $versions.'/current/fragments/herdr-commander-tasks.caddy';
    $legacyDirectory = $configurationRoot.'/commander-tasks';
    $nonceStore = $stateRoot.'/commander-tasks/nonces.json';

    foreach ([
        $legacyDirectory,
        dirname($nonceStore),
        $systemdDirectory,
        $versionOne.'/fragments',
        dirname($legacyFragment),
        dirname($lock),
        $systemctlState,
        $bin,
    ] as $directory) {
        mkdir($directory, 0700, true);
    }

    chmod(dirname($lock), 0700);
    chmod($legacyDirectory, 0750);
    file_put_contents($legacyDirectory.'/cert.pem', 'legacy certificate');
    file_put_contents($legacyDirectory.'/key.pem', 'legacy key');
    chmod($legacyDirectory.'/cert.pem', 0644);
    chmod($legacyDirectory.'/key.pem', 0600);
    file_put_contents($nonceStore, '{"used":4102444800}');
    chmod($nonceStore, 0600);
    file_put_contents($versionOne.'/fragments/unrelated.caddy', '# unrelated');
    file_put_contents($versionOne.'/Caddyfile', 'import '.$versionOne.'/fragments/*.caddy'.PHP_EOL);
    symlink($versionOne.'/Caddyfile', $liveCaddyfile);
    file_put_contents($legacyFragment, '# Managed by Orbit: herdr-observer'.PHP_EOL.'https://legacy.test {}');

    herdr_publisher_executable($bin.'/caddy', <<<'SH'
        #!/bin/sh
        test "${ORBIT_TEST_CADDY_RUNTIME_USER:-}" = caddy
        SH);
    herdr_publisher_executable($bin.'/runuser', <<<'SH'
        #!/bin/sh
        set -eu
        test "$1" = -u
        test "$2" = caddy
        test "$3" = --
        shift 3
        export ORBIT_TEST_CADDY_RUNTIME_USER=caddy
        exec "$@"
        SH);
    herdr_publisher_executable($bin.'/systemd-analyze', <<<'SH'
        #!/bin/sh
        exit 0
        SH);
    herdr_publisher_executable($bin.'/systemctl', <<<'SH'
        #!/bin/sh
        state=$ORBIT_TEST_SYSTEMCTL_STATE
        command=$1
        shift
        case "$command" in
            is-enabled)
                [ "$1" = --quiet ] && shift
                test -f "$state/enabled-$1"
                ;;
            is-active)
                [ "$1" = --quiet ] && shift
                test -f "$state/active-$1"
                ;;
            enable)
                touch "$state/enabled-$1"
                ;;
            restart)
                touch "$state/active-$1"
                ;;
            disable)
                [ "$1" = --now ] && shift
                if [ -f "$state/fail-disable-once" ]; then
                    rm -f "$state/fail-disable-once"
                    exit 1
                fi
                rm -f "$state/enabled-$1" "$state/active-$1"
                ;;
            daemon-reload|reload-or-restart)
                exit 0
                ;;
            *)
                exit 1
                ;;
        esac
        SH);
    herdr_publisher_executable($bin.'/herdr', "#!/bin/sh\nexit 0\n");

    return [
        'root' => $root,
        'user' => $user,
        'group' => $group,
        'configuration_root' => $configurationRoot,
        'state_root' => $stateRoot,
        'systemd_directory' => $systemdDirectory,
        'versions' => $versions,
        'live_caddyfile' => $liveCaddyfile,
        'temporary_directory' => $temporaryDirectory,
        'lock' => $lock,
        'systemctl_state' => $systemctlState,
        'legacy_fragment' => $legacyFragment,
        'nonce_store' => $nonceStore,
        'caddy' => $bin.'/caddy',
        'herdr' => $bin.'/herdr',
        'bin' => $bin,
    ];
}

function herdr_publisher_executable(string $path, string $contents): void
{
    file_put_contents($path, $contents);
    chmod($path, 0755);
}

/** @param array<string, string> $harness @return array{exit: int, stderr: string} */
function herdr_publisher_run(RemoteCommand $command, array $harness): array
{
    $pipes = [];
    $process = proc_open(
        ['/usr/bin/bash', '-seu', '--', ...array_slice($command->arguments, 4)],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        null,
        [
            'PATH' => $harness['bin'].':/usr/bin:/bin',
            'ORBIT_TEST_SYSTEMCTL_STATE' => $harness['systemctl_state'],
        ],
        ['bypass_shell' => true],
    );
    expect($process)->toBeResource();
    fwrite($pipes[0], (string) stream_get_contents($command->protectedInput?->stream()));
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    return ['exit' => proc_close($process), 'stderr' => $stderr];
}

final class HerdrPublisherTestCertificateSigner implements LeafCertificateSigner
{
    private OpenSSLAsymmetricKey $key;

    private OpenSSLCertificate $certificate;

    public function __construct()
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        expect($key)->toBeInstanceOf(OpenSSLAsymmetricKey::class);
        $request = openssl_csr_new(['commonName' => 'Orbit test CA'], $key, ['digest_alg' => 'sha256']);
        expect($request)->not->toBeFalse();
        $certificate = openssl_csr_sign($request, null, $key, 1, ['digest_alg' => 'sha256']);
        expect($certificate)->toBeInstanceOf(OpenSSLCertificate::class);
        $this->key = $key;
        $this->certificate = $certificate;
    }

    public function sign(string $hostname, string $certificateRequest): string
    {
        $certificate = openssl_csr_sign($certificateRequest, $this->certificate, $this->key, 1, ['digest_alg' => 'sha256']);
        expect($certificate)->toBeInstanceOf(OpenSSLCertificate::class);
        $pem = '';
        expect(openssl_x509_export($certificate, $pem))->toBeTrue();

        return $pem;
    }

    public function rootCertificate(): string
    {
        $pem = '';
        expect(openssl_x509_export($this->certificate, $pem))->toBeTrue();

        return $pem;
    }
}
