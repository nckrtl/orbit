<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

pest()->group('subprocess');

/**
 * A host copy of one guest script under a temporary root: `/home/orbit` becomes `<root>/home` and
 * `/var/lib/orbit-e2e` becomes `<root>/state`, and each stub is an executable in `<root>/bin`, first on PATH.
 *
 * @param  array<string, string>  $stubs  command name => Bash body
 * @return array{root:string,script:string,home:string,state:string,commands:string,path:string}
 */
function guestScriptFixture(string $name, array $stubs = []): array
{
    $root = temporaryPath('orbit-guest-script-', 4);
    foreach (["{$root}/bin", "{$root}/home/.orbit", "{$root}/home/orbit/apps/gateway", "{$root}/home/orbit/apps/cli", "{$root}/state"] as $directory) {
        mkdir($directory, 0o700, true);
    }
    foreach ($stubs as $command => $body) {
        file_put_contents("{$root}/bin/{$command}", "#!/usr/bin/env bash\n".str_replace('__ROOT__', $root, $body)."\n");
        chmod("{$root}/bin/{$command}", 0o700);
    }
    $script = "{$root}/{$name}";
    file_put_contents($script, str_replace(
        ['/home/orbit', '/var/lib/orbit-e2e'],
        ["{$root}/home", "{$root}/state"],
        guestScriptSource($name),
    ));
    chmod($script, 0o700);

    return [
        'root' => $root,
        'script' => $script,
        'home' => "{$root}/home",
        'state' => "{$root}/state",
        'commands' => "{$root}/commands",
        'path' => "{$root}/bin:".getenv('PATH'),
    ];
}

/** A `sudo` that drops `-u USER --` and runs the rest as the caller. */
const GUEST_SUDO_STUB = 'shift 3; exec "$@"';

/** An `orbit` CLI stub that logs its arguments and answers `node:list` from `<root>/nodes.json`. */
function guestOrbitStub(string $root): string
{
    $orbit = "{$root}/home/orbit/apps/cli/orbit";
    file_put_contents($orbit, str_replace('__ROOT__', $root, <<<'BASH'
        #!/usr/bin/env bash
        printf 'orbit %s\n' "$*" >> '__ROOT__/commands'
        case "$1" in
          node:list) cat '__ROOT__/nodes.json' ;;
          cluster:list) cat '__ROOT__/clusters.json' 2>/dev/null || printf '{"clusters":[]}\n' ;;
          cluster:create) printf '{"id":7,"name":"%s"}\n' "$2" ;;
          *) printf '{"ok":true}\n' ;;
        esac
        BASH));
    chmod($orbit, 0o700);

    return $orbit;
}

/** @param array<string, mixed> $environment */
function runGuestScript(array $fixture, array $arguments, array $environment = []): Process
{
    $process = new Process(['bash', $fixture['script'], ...$arguments], env: ['PATH' => $fixture['path'], ...$environment], timeout: 30);
    $process->run();

    return $process;
}

/**
 * The fixture of `converge-node.sh`: host keys stubbed, and `php` logs its arguments.
 *
 * @return array{root:string,script:string,home:string,state:string,commands:string,path:string}
 */
function convergeNodeFixture(): array
{
    return guestScriptFixture('converge-node.sh', [
        'sudo' => GUEST_SUDO_STUB,
        'ssh-keyscan' => "printf 'ssh-keyscan %s\\n' \"\$*\" >> '__ROOT__/commands'\nprintf '%s\\n' 'host ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOJgN5jVtcfw7oASD2F6If4O5mQ/HZBqbrw4QC9PcHEO'",
        'ssh-keygen' => "cat >/dev/null\nprintf '256 SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA host (ED25519)\\n'",
        'php' => <<<'BASH'
            printf 'php %s\n' "$*" >> '__ROOT__/commands'
            [[ -z "${ORBIT_PHP_FAIL:-}" ]]
            BASH,
    ]);
}

/** @return array{root:string,script:string,home:string,state:string,commands:string,path:string} */
function operatorFixture(): array
{
    $fixture = guestScriptFixture('converge-operator.sh', ['sudo' => GUEST_SUDO_STUB]);
    guestOrbitStub($fixture['root']);

    return $fixture;
}

/** @return array{root:string,script:string,home:string,state:string,commands:string,path:string} */
function verifierFixture(): array
{
    $fixture = guestScriptFixture('verify-topology.sh', [
        'sudo' => GUEST_SUDO_STUB,
        'mountpoint' => 'exit "${MOUNTPOINT_EXIT:-0}"',
    ]);
    guestOrbitStub($fixture['root']);

    return $fixture;
}

/**
 * @param  array<string, list<string>>  $declared
 * @param  list<array<string, mixed>>  $listed
 */
function nodesRegistered(array $fixture, array $declared, array $listed): Process
{
    file_put_contents("{$fixture['root']}/nodes.json", json_encode(['nodes' => $listed], JSON_THROW_ON_ERROR));

    return runGuestScript($fixture, ['nodes.registered', str_repeat('a', 40), 'orbit-e2e-tst-9-aaaaaaaa-gateway', base64_encode(json_encode($declared, JSON_THROW_ON_ERROR))]);
}

function declaredNodes(): array
{
    return ['gateway' => ['gateway', 'vpn'], 'app-dev' => ['app-dev'], 'operator' => []];
}

/** @return list<string> */
function commandLog(array $fixture): array
{
    return is_file($fixture['commands']) ? file($fixture['commands'], FILE_IGNORE_NEW_LINES) : [];
}

describe('Gateway convergence', function () {
    it('skips apt when all Gateway prerequisites are installed', function () {
        $fixture = guestScriptFixture('converge-gateway.sh', [
            'dpkg-query' => "printf 'install ok installed'",
            'apt-get' => "printf '%s\\n' \"\$*\" >> '__ROOT__/commands'",
        ]);

        expect(runGuestScript($fixture, ['prerequisites'])->getExitCode())->toBe(0)
            ->and(commandLog($fixture))->toBe([]);
    });

    it('installs only the missing Gateway prerequisites in fixed order', function (string $missing, string $packages) {
        $fixture = guestScriptFixture('converge-gateway.sh', [
            'dpkg-query' => "case \",\$DPKG_MISSING_PACKAGES,\" in *\",\${@: -1},\"*) exit 1 ;; esac\nprintf 'install ok installed'",
            'apt-get' => "printf '%s %s\\n' \"\$DEBIAN_FRONTEND\" \"\$*\" >> '__ROOT__/commands'",
        ]);

        expect(runGuestScript($fixture, ['prerequisites'], ['DPKG_MISSING_PACKAGES' => $missing])->getExitCode())->toBe(0)
            ->and(commandLog($fixture))->toBe([
                'noninteractive update',
                'noninteractive install --yes --no-install-recommends -- '.$packages,
            ]);
    })->with([
        'Caddy' => ['caddy', 'caddy'],
        'dnsmasq' => ['dnsmasq', 'dnsmasq'],
        'all' => ['dnsmasq,caddy', 'caddy dnsmasq'],
    ]);

    it('points a Gateway environment that names another checkout at the mounted checkout', function () {
        $fixture = guestScriptFixture('converge-gateway.sh', ['dpkg-query' => "printf 'install ok installed'"]);
        $environment = "{$fixture['home']}/orbit/apps/gateway/.env";
        file_put_contents($environment, "APP_ENV=local\nORBIT_GATEWAY_CHECKOUT=/home/orbit/orbit-gateway\nORBIT_HOME=/home/orbit/.orbit\n");

        expect(runGuestScript($fixture, ['prerequisites'])->getExitCode())->toBe(0)
            ->and(file_get_contents($environment))
            ->toBe("APP_ENV=local\nORBIT_HOME=/home/orbit/.orbit\nORBIT_GATEWAY_CHECKOUT={$fixture['home']}/orbit/apps/gateway\n");
    });

    it('runs Gateway Artisan commands from the Gateway checkout', function () {
        $fixture = guestScriptFixture('converge-gateway.sh', [
            'sudo' => GUEST_SUDO_STUB,
            'install' => 'mkdir -p "${@: -1}"',
            'chown' => 'exit 0',
            'php' => "printf '%s %s\\n' \"\$PWD\" \"\$*\" >> '__ROOT__/commands'",
        ]);

        expect(runGuestScript($fixture, ['bootstrap', 'gateway'])->getExitCode())->toBe(0)
            ->and(commandLog($fixture))->toBe([
                "{$fixture['home']}/orbit/apps/gateway artisan migrate --force",
                "{$fixture['home']}/orbit/apps/gateway artisan orbit:bootstrap gateway --no-interaction",
            ]);
    });

    it('keeps every guest script valid Bash', function () {
        $resources = dirname(__DIR__, 3).'/resources';
        $scripts = glob($resources.'/guest/*.sh');

        expect($scripts)->toHaveCount(6);
        foreach ($scripts as $script) {
            expect(new Process(['bash', '-n', $script], timeout: 5)->run())->toBe(0, $script);
        }
    });
});

describe('Node convergence', function () {
    it('provisions a workload Node with its base role, TLD, WireGuard address, and scanned host key', function (string $node, string $role, string $address) {
        $fixture = convergeNodeFixture();

        expect(runGuestScript($fixture, ['provision', $node, '192.0.2.12', 'aarch64', $address, $role, $node])->getExitCode())->toBe(0)
            ->and(commandLog($fixture))->toBe([
                'ssh-keyscan -T 5 -t ed25519 -- 192.0.2.12',
                "php artisan orbit:node-provision {$node} 192.0.2.12 --role={$role} --tld={$node} --architecture=aarch64 --user=orbit "
                    ."--wireguard-ip={$address} --host-key-fingerprint=SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA --diagnostics --no-interaction",
            ]);
    })->with([
        'development' => ['app-dev', 'app-dev', '10.44.0.2'],
        'production' => ['app-prod', 'app-prod', '10.44.0.3'],
        'second production' => ['app-prod-2', 'app-prod', '10.44.0.4'],
    ]);

    it('provisions the roleless operator without a role or TLD', function () {
        $fixture = convergeNodeFixture();

        expect(runGuestScript($fixture, ['provision', 'operator', '192.0.2.14', 'x86_64', '10.44.0.5'])->getExitCode())->toBe(0)
            ->and(commandLog($fixture)[1])->toBe(
                'php artisan orbit:node-provision operator 192.0.2.14 --architecture=x86_64 --user=orbit --wireguard-ip=10.44.0.5 '
                    .'--host-key-fingerprint=SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA --diagnostics --no-interaction',
            );
    });

    it('fails closed when provisioning fails', function () {
        $fixture = convergeNodeFixture();

        expect(runGuestScript($fixture, ['provision', 'app-dev', '192.0.2.11', 'x86_64', '10.44.0.2', 'app-dev', 'beast'], ['ORBIT_PHP_FAIL' => '1'])->getExitCode())->not->toBe(0);
    });

    it('refuses invalid arguments before provisioning', function (array $arguments, int $exit) {
        $fixture = convergeNodeFixture();

        expect(runGuestScript($fixture, $arguments)->getExitCode())->toBe($exit)
            ->and(commandLog($fixture))->toBe([]);
    })->with([
        'no mode' => [['app-dev', '192.0.2.11', 'x86_64', '10.44.0.2'], 64],
        'arity' => [['provision', 'app-dev', '192.0.2.11', 'x86_64'], 64],
        'role without TLD' => [['provision', 'app-dev', '192.0.2.11', 'x86_64', '10.44.0.2', 'app-dev'], 64],
        'address' => [['provision', 'app-dev', 'example', 'x86_64', '10.44.0.2'], 64],
        'Node key' => [['provision', 'App_Dev', '192.0.2.11', 'x86_64', '10.44.0.2'], 64],
        'architecture' => [['provision', 'app-dev', '192.0.2.11', 'armv7', '10.44.0.2'], 65],
        'WireGuard address' => [['provision', 'app-prod', '192.0.2.12', 'x86_64', '10.45.0.3'], 65],
        'role' => [['provision', 'app-prod', '192.0.2.12', 'x86_64', '10.44.0.3', 'App Prod', 'app-prod'], 64],
    ]);

    it('installs no product software and writes no Caddy file on a Node', function () {
        foreach (['converge-node.sh', 'converge-gateway.sh'] as $script) {
            expect(guestScriptSource($script))
                ->not->toContain('/etc/caddy', 'local_certs', 'caddy validate', 'systemctl reload caddy');
        }
        expect(guestScriptSource('converge-node.sh'))->not->toContain('apt-get', 'php8.5-fpm', 'PDO');
        // The recipe base image carries the recipe's exact PHP build, so Gateway convergence installs no PHP package.
        expect(guestScriptSource('converge-gateway.sh'))->not->toMatch('/\bphp\d/');
    });
});

describe('operator convergence', function () {
    it('refuses invalid arguments for each mode before any work', function (array $arguments) {
        $fixture = operatorFixture();

        expect(runGuestScript($fixture, $arguments)->getExitCode())->toBe(64)
            ->and(commandLog($fixture))->toBe([]);
    })->with([
        'no mode' => [[]],
        'unknown mode' => [['retarget']],
        'provision moved to converge-node.sh' => [['provision', '192.0.2.14', 'x86_64', '10.44.0.5']],
        'grant arity' => [['grant', 'operator', 'gateway']],
        'grant Node name' => [['grant', 'operator', 'gate way', '10.44.0.1']],
        'grant Gateway address' => [['grant', 'operator', 'gateway', '192.0.2.10']],
        'configure-cli arity' => [['configure-cli']],
        'configure-cli Gateway host' => [['configure-cli', '-gateway']],
        'role-add arity' => [['role-add', 'app-prod']],
        'role-add Node' => [['role-add', 'App_Prod', 'ingress']],
        'role-add role' => [['role-add', 'app-prod', 'Ingress']],
        'role-add Cluster' => [['role-add', 'app-prod', 'ingress', 'e2e app-prod']],
    ])->skip(fn (): bool => posix_geteuid() === 0, 'grant, configure-cli, and role-add re-run themselves through sudo as root.');

    it('puts the Node in the topology Cluster, then adds ingress through the topology Gateway', function () {
        $fixture = operatorFixture();
        file_put_contents("{$fixture['root']}/nodes.json", json_encode(['nodes' => [
            ['id' => 1, 'name' => 'gateway', 'cluster_id' => null],
            ['id' => 2, 'name' => 'app-prod', 'cluster_id' => null],
        ]], JSON_THROW_ON_ERROR));

        expect(runGuestScript($fixture, ['role-add', 'app-prod', 'ingress', 'e2e-app-prod'])->getExitCode())->toBe(0)
            ->and(commandLog($fixture))->toBe([
                'orbit cluster:list --json',
                'orbit cluster:create e2e-app-prod --json',
                'orbit node:list --json',
                'orbit cluster:node:add 7 2 --json',
                'orbit node:role:add app-prod ingress --json',
            ]);
    })->skip(fn (): bool => posix_geteuid() === 0, 'role-add re-runs itself through sudo as root.');

    it('reuses the topology Cluster and a Node already in it', function () {
        $fixture = operatorFixture();
        file_put_contents("{$fixture['root']}/clusters.json", json_encode(['clusters' => [['id' => 2, 'name' => 'e2e-app-prod'], ['id' => 3, 'name' => 'e2e-app-prod-2']]], JSON_THROW_ON_ERROR));
        file_put_contents("{$fixture['root']}/nodes.json", json_encode(['nodes' => [['id' => 4, 'name' => 'app-prod-2', 'cluster_id' => 3]]], JSON_THROW_ON_ERROR));

        expect(runGuestScript($fixture, ['role-add', 'app-prod-2', 'ingress', 'e2e-app-prod-2'])->getExitCode())->toBe(0)
            ->and(commandLog($fixture))->toBe([
                'orbit cluster:list --json',
                'orbit node:list --json',
                'orbit node:role:add app-prod-2 ingress --json',
            ]);
    })->skip(fn (): bool => posix_geteuid() === 0, 'role-add re-runs itself through sudo as root.');

    it('adds a role without a Cluster when none is named', function () {
        $fixture = operatorFixture();

        expect(runGuestScript($fixture, ['role-add', 'app-dev', 'database'])->getExitCode())->toBe(0)
            ->and(commandLog($fixture))->toBe(['orbit node:role:add app-dev database --json']);
    })->skip(fn (): bool => posix_geteuid() === 0, 'role-add re-runs itself through sudo as root.');

    it('adds the Gateway, then grants the operator Node access to the Gateway Node by id', function () {
        $fixture = operatorFixture();
        mkdir("{$fixture['home']}/.orbit/ca", 0o700);
        file_put_contents("{$fixture['home']}/.orbit/ca/root.pem", "root CA\n");
        file_put_contents("{$fixture['root']}/nodes.json", json_encode(['nodes' => [
            ['id' => 1, 'name' => 'gateway'],
            ['id' => 3, 'name' => 'app-dev'],
            ['id' => 4, 'name' => 'operator'],
        ]], JSON_THROW_ON_ERROR));

        expect(runGuestScript($fixture, ['grant', 'operator', 'gateway', '10.44.0.1'])->getExitCode())->toBe(0)
            ->and(commandLog($fixture))->toBe([
                "orbit gateway:add https://10.44.0.1 --name=e2e --ca={$fixture['home']}/.orbit/ca/root.pem --use --json",
                'orbit node:list --json',
                'orbit node:access:add 4 1 --json',
            ]);
    })->skip(fn (): bool => posix_geteuid() === 0, 'grant re-runs itself through sudo as root.');

    it('grants nothing when a named Node is not registered', function () {
        $fixture = operatorFixture();
        mkdir("{$fixture['home']}/.orbit/ca", 0o700);
        file_put_contents("{$fixture['home']}/.orbit/ca/root.pem", "root CA\n");
        file_put_contents("{$fixture['root']}/nodes.json", json_encode(['nodes' => [['id' => 1, 'name' => 'gateway']]], JSON_THROW_ON_ERROR));

        expect(runGuestScript($fixture, ['grant', 'operator', 'gateway', '10.44.0.1'])->getExitCode())->toBe(65)
            ->and(implode("\n", commandLog($fixture)))->not->toContain('node:access:add');
    })->skip(fn (): bool => posix_geteuid() === 0, 'grant re-runs itself through sudo as root.');
});

describe('topology verifier', function () {

    it('refuses arguments outside its contract without evidence', function (array $arguments) {
        $process = runGuestScript(verifierFixture(), $arguments);

        expect($process->getExitCode())->toBe(64)
            ->and($process->getOutput())->toBe('');
    })->with([
        'no arguments' => [[]],
        'no instance' => [['service.gateway', str_repeat('a', 40)]],
        'short source SHA' => [['service.gateway', 'abc', 'orbit-e2e-gateway']],
        'invalid instance' => [['service.gateway', str_repeat('a', 40), 'Orbit_Gateway']],
        'unknown probe' => [['role.gateway', str_repeat('a', 40), 'orbit-e2e-gateway']],
        'Node set missing' => [['nodes.registered', str_repeat('a', 40), 'orbit-e2e-gateway']],
        'Node set not base64' => [['nodes.registered', str_repeat('a', 40), 'orbit-e2e-gateway', '{"gateway":[]}']],
        'no reachable Nodes' => [['wireguard.reachability', str_repeat('a', 40), 'orbit-e2e-gateway']],
        'reachable Node without address' => [['wireguard.reachability', str_repeat('a', 40), 'orbit-e2e-gateway', 'app-dev']],
        'reachable Node outside WireGuard' => [['wireguard.reachability', str_repeat('a', 40), 'orbit-e2e-gateway', 'app-dev=192.0.2.11']],
        'mount token not a hash' => [['source.app-dev', str_repeat('a', 40), 'orbit-e2e-app-dev', 'token']],
    ]);

    it('passes nodes.registered on the exact declared Nodes and prints one JSON evidence line', function () {
        $process = nodesRegistered(verifierFixture(), declaredNodes(), [
            ['name' => 'gateway', 'status' => 'active', 'roles' => ['vpn', 'gateway', 'metrics']],
            ['name' => 'app-dev', 'status' => 'active', 'roles' => ['app-dev']],
            ['name' => 'operator', 'status' => 'active', 'roles' => []],
        ]);

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and(substr_count($process->getOutput(), "\n"))->toBe(1);
        $evidence = json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR);
        expect(array_keys($evidence))->toBe(['probe', 'passed', 'identity', 'checked_at', 'expected', 'observed', 'evidence_ref'])
            ->and($evidence)->toMatchArray([
                'probe' => 'nodes.registered',
                'passed' => true,
                'identity' => str_repeat('a', 40),
                'expected' => 'gateway:gateway+vpn,app-dev:app-dev,operator:none',
                'observed' => 'gateway:gateway+metrics+vpn,app-dev:app-dev,operator:none',
                'evidence_ref' => 'incus://orbit-e2e-tst-9-aaaaaaaa-gateway/nodes.registered',
            ])
            ->and($evidence['checked_at'])->toMatch('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\z/');
    });

    it('fails nodes.registered unless exactly the declared Nodes hold their roles', function (array $listed, string $reason) {
        $process = nodesRegistered(verifierFixture(), declaredNodes(), $listed);

        expect($process->getExitCode())->toBe(1)
            ->and($process->getOutput())->toBe('')
            ->and($process->getErrorOutput())->toContain($reason);
    })->with([
        'undeclared Node' => [[
            ['name' => 'gateway', 'status' => 'active', 'roles' => ['gateway', 'vpn']],
            ['name' => 'app-dev', 'status' => 'active', 'roles' => ['app-dev']],
            ['name' => 'app-prod', 'status' => 'active', 'roles' => ['app-prod']],
            ['name' => 'operator', 'status' => 'active', 'roles' => []],
        ], 'registered Nodes [app-dev,app-prod,gateway,operator] differ from declared [app-dev,gateway,operator]'],
        'missing Node' => [[
            ['name' => 'gateway', 'status' => 'active', 'roles' => ['gateway', 'vpn']],
            ['name' => 'operator', 'status' => 'active', 'roles' => []],
        ], 'registered Nodes [gateway,operator] differ from declared [app-dev,gateway,operator]'],
        'missing role' => [[
            ['name' => 'gateway', 'status' => 'active', 'roles' => ['gateway']],
            ['name' => 'app-dev', 'status' => 'active', 'roles' => ['app-dev']],
            ['name' => 'operator', 'status' => 'active', 'roles' => []],
        ], 'Node gateway lacks roles [vpn]'],
        'operator with a role' => [[
            ['name' => 'gateway', 'status' => 'active', 'roles' => ['gateway', 'vpn']],
            ['name' => 'app-dev', 'status' => 'active', 'roles' => ['app-dev']],
            ['name' => 'operator', 'status' => 'active', 'roles' => ['app-dev']],
        ], 'Node operator must stay roleless'],
        'inactive Node' => [[
            ['name' => 'gateway', 'status' => 'active', 'roles' => ['gateway', 'vpn']],
            ['name' => 'app-dev', 'status' => 'provisioning', 'roles' => ['app-dev']],
            ['name' => 'operator', 'status' => 'active', 'roles' => []],
        ], 'Node app-dev is not active'],
    ]);

    it('passes source on a mounted worktree only when its mount token hashes to the recorded hash', function (string $change, int $exit) {
        $fixture = verifierFixture();
        $sha = str_repeat('c', 40);
        $token = bin2hex(random_bytes(32))."\n";
        $hash = hash('sha256', $token);
        mkdir("{$fixture['home']}/orbit/.e2e", 0o700);
        file_put_contents("{$fixture['home']}/orbit/apps/gateway/artisan", "<?php\n");
        file_put_contents("{$fixture['home']}/orbit/.e2e/mount-token", $change === 'token rotated' ? "rotated\n" : $token);
        file_put_contents("{$fixture['state']}/source-state", json_encode(['sha' => $sha, 'mounted' => true, 'mount_token_sha256' => $hash], JSON_THROW_ON_ERROR));
        $expected = $change === 'other hash expected' ? str_repeat('d', 64) : $hash;

        $process = runGuestScript($fixture, ['source.app-dev', $sha, 'orbit-e2e-app-dev', $expected], ['MOUNTPOINT_EXIT' => $change === 'not mounted' ? '32' : '0']);

        expect($process->getExitCode())->toBe($exit);
        if ($exit === 0) {
            expect(json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR))->toMatchArray([
                'probe' => 'source.app-dev',
                'expected' => "{$sha}:mount-token={$hash}",
                'observed' => "{$sha}:mount-token={$hash}",
                'evidence_ref' => 'incus://orbit-e2e-app-dev/source.app-dev',
            ]);
        } else {
            expect($process->getOutput())->toBe('');
        }
    })->with([
        'mounted' => ['none', 0],
        'token rotated' => ['token rotated', 1],
        'other hash expected' => ['other hash expected', 1],
        'not mounted' => ['not mounted', 1],
    ]);
});

it('sets topology guest public DNS before cloud-init and restarts the resolver only when configuration changes', function (): void {
    $work = temporaryPath('orbit-topology-dns-', 6);
    mkdir($work.'/bin', 0755, true);
    file_put_contents($work.'/bin/systemctl', '#!/bin/sh'."\n".'printf "%s\n" "$*" >> "$WORK/calls"');
    chmod($work.'/bin/systemctl', 0755);
    $source = str_replace('/etc/systemd/resolved.conf.d', $work.'/resolved', guestScriptSource('public-dns.sh'));
    foreach ([1, 2] as $attempt) {
        $process = new Process(['bash', '-ceu', $source], env: ['PATH' => $work.'/bin:'.getenv('PATH'), 'WORK' => $work]);
        expect($process->run())->toBe(0, $process->getErrorOutput());
    }
    expect(file_get_contents($work.'/resolved/orbit-public-upstream.conf'))->toBe("[Resolve]\nDNS=1.1.1.1 8.8.8.8\n");
    expect(file($work.'/calls', FILE_IGNORE_NEW_LINES))->toBe(['is-active --quiet systemd-resolved', 'restart systemd-resolved']);
});
