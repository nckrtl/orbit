<?php

declare(strict_types=1);

use App\Infrastructure\Nodes\NodeBootstrapCommandFactory;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('uses existing network DNS only during packages and restores the retained owned resolver', function (): void {
    $result = run_bootstrap_dns_shell();

    expect($result['exit'])->toBe(0);
    expect($result['events'])->toBe([
        'lock', 'update', 'probe archive.example.test', 'dns ', 'domain ', 'probe archive.example.test',
        'update', 'install', 'dns 10.44.0.1', 'domain ~.', 'identity-setup',
    ]);
    expect($result['dns'])->toBe('10.44.0.1');
    expect($result['domain'])->toBe('~.');
    expect($result['marker'])->toBe("orbit\n10.44.0.1\n.\n");
    expect($result['policy'])->toBe('default-route=no;llmnr=no;mdns=yes;dnssec=yes;dnsovertls=yes;nta=corp.test');
});

it('leaves working APT configurations untouched without imposing local origin resolution', function (array $options): void {
    $result = run_bootstrap_dns_shell($options);

    expect($result['exit'])->toBe(0);
    expect($result['events'])->toBe(['lock', 'update', 'install', 'identity-setup']);
    expect($result['dns'])->toBe('10.44.0.1');
    expect($result['domain'])->toBe('~.');
})->with([
    'fresh healthy machine' => [['healthy' => true, 'marker' => null]],
    'already managed healthy machine' => [['healthy' => true]],
    'operator-owned proxy resolves origin' => [['proxy' => true, 'operator' => true, 'marker' => null]],
    'managed proxy resolves origin' => [['proxy' => true]],
    'APT mirror transport' => [['healthy' => true, 'uris' => "'mirror+https://archive.example.test/path' metadata 0\n"]],
]);

it('preserves unowned or explicitly overridden DNS when name resolution fails', function (array $options): void {
    $result = run_bootstrap_dns_shell($options);

    expect($result['exit'])->toBe(1);
    expect($result['events'])->toBe(['lock', 'update', 'probe archive.example.test']);
    expect($result['dns'])->toBe($options['dns'] ?? '10.44.0.1');
    expect($result['domain'])->toBe($options['domain'] ?? '~.');
    expect($result['stderr'])->toContain('Package-source DNS is unavailable;');
    expect($result['injected'])->toBeFalse();
})->with([
    'missing marker' => [['marker' => null]],
    'marker symlink' => [['symlink' => true]],
    'wrong permissions' => [['permissions' => '0:666']],
    'wrong owner' => [['permissions' => '1000:600']],
    'underlay marker' => [['marker' => "eth0\n10.44.0.1\n.\n"]],
    'split DNS' => [['marker' => "orbit\n10.44.0.1\nexample.test\n"]],
    'malformed marker' => [['marker' => "orbit\n$(touch injected)\n.\n"]],
    'extra marker fields' => [['marker' => "orbit\n10.44.0.1\n.\nother.test\n"]],
    'operator peer' => [['operator' => true]],
    'explicit DNS override' => [['override' => '192.0.2.53']],
    'changed live server' => [['dns' => '192.0.2.53']],
    'additional live server' => [['dns' => '10.44.0.1 192.0.2.53']],
    'additional live domain' => [['domain' => '~. operator.test']],
]);

it('restores owned resolver state on package or resolver failure before identity setup', function (string $failure, int $exit): void {
    $result = run_bootstrap_dns_shell(['failure' => $failure]);

    expect($result['exit'])->toBe($exit);
    expect($result['events'])->not->toContain('identity-setup');
    expect(array_slice($result['events'], -2))->toBe(['dns 10.44.0.1', 'domain ~.']);
    expect($result['dns'])->toBe('10.44.0.1');
    expect($result['domain'])->toBe('~.');
    if ($failure === 'underlay') {
        expect($result['events'])->not->toContain('install');
        expect($result['stderr'])->toContain('existing network resolver');
    }
})->with([
    'no underlay DNS' => ['underlay', 1],
    'clear DNS failed' => ['clear-dns', 7],
    'clear domain failed' => ['clear-domain', 7],
    'update failed' => ['update', 9],
    'install failed' => ['install', 9],
    'update timeout' => ['update-timeout', 124],
    'termination' => ['term', 143],
]);

it('reports restore failure and still attempts both properties', function (string $failure, string $dns, string $domain): void {
    $result = run_bootstrap_dns_shell(['failure' => $failure]);

    expect($result['exit'])->toBe(1);
    expect(array_slice($result['events'], -2))->toBe(['dns 10.44.0.1', 'domain ~.']);
    expect($result['events'])->not->toContain('identity-setup');
    expect($result['dns'])->toBe($dns);
    expect($result['domain'])->toBe($domain);
    expect($result['stderr'])->toContain('Could not restore Orbit bootstrap DNS');
})->with([
    'server restore fails' => ['restore-dns', '', '~.'],
    'domain restore fails' => ['restore-domain', '10.44.0.1', ''],
]);

it('checks configured package hosts without executing source text', function (): void {
    $result = run_bootstrap_dns_shell([
        'uris' => "'https://user:secret@archive.example.test:443/path' metadata 0\n'http://[2001:db8::1]/path' metadata 0\n'file:/local/path' metadata 0\n'http://archive.example.test/another' metadata 0\n",
    ]);

    expect($result['exit'])->toBe(0);
    expect($result['events'])->toContain('probe archive.example.test', 'probe 2001:db8::1');
    expect($result['stderr'])->not->toContain('secret');
});

it('stops failed or unsupported source diagnosis before resolver changes', function (array $options): void {
    $result = run_bootstrap_dns_shell($options);

    expect($result['exit'])->toBe(1);
    expect($result['events'])->toBe(['lock', 'update']);
    expect($result['dns'])->toBe('10.44.0.1');
    expect($result['injected'])->toBeFalse();
})->with([
    'APT source inspection failed' => [['failure' => 'sources']],
    'unsupported recovery method' => [['uris' => "'mirror+https://archive.example.test/path' metadata 0\n"]],
    'shell payload host' => [['uris' => "'https://$(touch injected)/path' metadata 0\n"]],
]);

it('stops before package or resolver changes when the peer lock is unavailable', function (): void {
    $result = run_bootstrap_dns_shell(['failure' => 'lock']);

    expect($result['exit'])->toBe(1);
    expect($result['events'])->toBe(['lock']);
    expect($result['dns'])->toBe('10.44.0.1');
    expect($result['stderr'])->toContain('Could not lock Orbit DNS');
});

it('holds the real peer lock through restore before a concurrent DNS publisher proceeds', function (): void {
    $result = run_bootstrap_dns_shell(['contend' => true]);

    expect($result['exit'])->toBe(0);
    expect($result['contender'])->toBe('10.44.0.1|~.');
    expect($result['dns'])->toBe('192.0.2.53');
})->skip(PHP_OS_FAMILY !== 'Linux', 'Uses the production Linux flock utility.');

/**
 * @param  array<string, mixed>  $options
 * @return array{exit: ?int, stderr: string, events: list<string>, dns: string, domain: string, marker: string|false, policy: string, injected: bool, contender: string|null}
 */
function run_bootstrap_dns_shell(array $options = []): array
{
    $filesystem = new Filesystem;
    $root = sys_get_temp_dir().'/orbit-bootstrap-dns-'.Str::random(16);
    $filesystem->makeDirectory($root, 0o700);
    $marker = array_key_exists('marker', $options) ? $options['marker'] : "orbit\n10.44.0.1\n.\n";
    if ($marker !== null) {
        file_put_contents($root.'/dns-link', $marker);
    }
    if ($options['symlink'] ?? false) {
        rename($root.'/dns-link', $root.'/dns-target');
        symlink($root.'/dns-target', $root.'/dns-link');
    }
    file_put_contents($root.'/os-release', "ID=ubuntu\nVERSION_CODENAME=resolute\n");
    file_put_contents($root.'/dns', $options['dns'] ?? '10.44.0.1');
    file_put_contents($root.'/domain', $options['domain'] ?? '~.');
    file_put_contents($root.'/policy', 'default-route=no;llmnr=no;mdns=yes;dnssec=yes;dnsovertls=yes;nta=corp.test');
    file_put_contents($root.'/events', '');
    file_put_contents($root.'/uris', $options['uris'] ?? "'http://archive.example.test/ubuntu/dists/resolute/InRelease' metadata 0\n");
    $keys = new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/unused/key';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 FIXTURE';
        }
    };
    $node = new Node;
    $node->dns_server_override = $options['override'] ?? null;
    $command = new NodeBootstrapCommandFactory($keys)->make($node, 'orbit', $options['operator'] ?? false);
    $script = strstr($command->input ?? '', 'user_created=false', true);
    if (! is_string($script)) {
        throw new RuntimeException('Bootstrap identity boundary was not found.');
    }
    $script = str_replace(['/etc/os-release', '/etc/wireguard/orbit.dns-link', '/etc/wireguard', '/run/lock/orbit-wireguard-peer.lock'], [$root.'/os-release', $root.'/dns-link', $root, $root.'/peer.lock'], $script);
    $shims = <<<'BASH'
        timeout() {
            shift 2
            "$@"
        }
        flock() {
            printf 'lock\n' >> events
            [ "$FAILURE" != lock ] || return 1
            if [ "$CONTEND" = 1 ]; then
                command flock "$@"
            fi
        }
        stat() { printf '%s\n' "$PERMISSIONS"; }
        getent() {
            [ "$1" = ahosts ] || return 80
            printf 'probe %s\n' "$2" >> events
            [ "$FAILURE" != underlay ] || return 2
            [ "$HEALTHY" = 1 ] || { [ ! -s dns ] && [ ! -s domain ]; }
        }
        resolvectl() {
            [ "$2" = orbit ] || return 81
            case "$1" in dns|domain) ;; *) return 82 ;; esac
            if [ "$#" = 2 ]; then
                printf 'Link 9 (orbit): %s\n' "$(cat "$1")"
                return
            fi
            printf '%s %s\n' "$1" "$3" >> events
            if [ -z "$3" ]; then
                [ "$FAILURE" != "clear-$1" ] || return 7
            else
                [ "$FAILURE" != "restore-$1" ] || return 7
            fi
            printf '%s' "$3" > "$1"
        }
        apt-get() {
            if [ "$1" = --print-uris ]; then
                [ "$FAILURE" != sources ] || return 8
                cat uris
                return
            fi
            mode=
            for arg in "$@"; do
                case "$arg" in update|install) mode=$arg ;; esac
            done
            printf '%s\n' "$mode" >> events
            [ "$HEALTHY" = 1 ] || [ "$PROXY" = 1 ] || { [ ! -s dns ] && [ ! -s domain ]; } || return 83
            [ "$FAILURE" != "$mode" ] || return 9
            [ "$FAILURE" != update-timeout ] || return 124
            if [ "$FAILURE" = term ]; then
                kill -TERM "$BASHPID"
            fi
            if [ "$CONTEND" = 1 ] && [ "$mode" = install ]; then
                (
                    trap - HUP EXIT INT TERM
                    exec 9>peer.lock
                    if command flock -n 9; then
                        printf 'acquired-before-restoration' > contender-state
                        exit 1
                    fi
                    touch contender-blocked
                    command flock -w 2 9 || exit 1
                    printf '%s|%s' "$(cat dns)" "$(cat domain)" > contender-state
                    printf '192.0.2.53' > dns.tmp
                    mv -f dns.tmp dns
                    touch contender-published
                ) &
                for attempt in {1..100}; do
                    [ ! -e contender-state ] || return 84
                    [ ! -e contender-blocked ] || break
                    sleep 0.01
                done
                [ -e contender-blocked ] || return 85
            fi
        }
        BASH;
    $bash = is_executable('/opt/homebrew/bin/bash') ? '/opt/homebrew/bin/bash' : '/bin/bash';
    $process = new Process([$bash, ...array_slice($command->arguments, 1)], $root, [
        'FAILURE' => $options['failure'] ?? '',
        'HEALTHY' => ($options['healthy'] ?? false) ? '1' : '0',
        'PROXY' => ($options['proxy'] ?? false) ? '1' : '0',
        'CONTEND' => ($options['contend'] ?? false) ? '1' : '0',
        'PERMISSIONS' => $options['permissions'] ?? '0:600',
    ]);
    $process->setInput($shims."\n".$script."\nprintf 'identity-setup\\n' >> events\n");
    $process->setTimeout(10);
    try {
        $process->run();
        if (($options['contend'] ?? false) === true) {
            $deadline = microtime(true) + 2.0;
            while (! is_file($root.'/contender-published') && microtime(true) < $deadline) {
                usleep(5000);
            }
        }

        return [
            'exit' => $process->getExitCode(),
            'stderr' => $process->getErrorOutput(),
            'events' => file($root.'/events', FILE_IGNORE_NEW_LINES) ?: [],
            'dns' => (string) file_get_contents($root.'/dns'),
            'domain' => (string) file_get_contents($root.'/domain'),
            'marker' => is_file($root.'/dns-link') ? file_get_contents($root.'/dns-link') : false,
            'policy' => (string) file_get_contents($root.'/policy'),
            'injected' => file_exists($root.'/injected'),
            'contender' => is_file($root.'/contender-state') ? (string) file_get_contents($root.'/contender-state') : null,
        ];
    } finally {
        $filesystem->deleteDirectory($root);
    }
}
