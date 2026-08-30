<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

function gateway_prerequisite_fixture(): string
{
    $root = temporaryPath('orbit-gateway-prereqs-', 4);
    mkdir("{$root}/bin", 0o700, true);
    file_put_contents("{$root}/bin/dpkg-query", <<<'BASH'
        #!/usr/bin/env bash
        case ",${DPKG_MISSING_PACKAGES:-}," in
          *",${@: -1},"*) exit 1 ;;
        esac
        printf 'install ok installed'
        BASH);
    file_put_contents(
        "{$root}/bin/apt-get",
        "#!/usr/bin/env bash\nprintf '%s %s\\n' \"\$DEBIAN_FRONTEND\" \"\$*\" >> '{$root}/apt'\n",
    );
    foreach (['dpkg-query', 'apt-get'] as $command) {
        chmod("{$root}/bin/{$command}", 0o700);
    }
    $source = file_get_contents(dirname(__DIR__, 3).'/resources/guest/converge-gateway.sh');
    file_put_contents("{$root}/converge-gateway.sh", $source);
    chmod("{$root}/converge-gateway.sh", 0o700);

    return $root;
}

/** @return array{root:string, checkout:string, script:string, environment:array<string, string>} */
function sample_hydration_fixture(bool $commitPresent = true): array
{
    $root = temporaryPath('orbit-sample-hydration-', 4);
    $checkout = "{$root}/checkout";
    mkdir("{$root}/bin", 0o700, true);
    mkdir("{$checkout}/.git", 0o700, true);
    mkdir("{$checkout}/vendor", 0o700, true);
    file_put_contents("{$checkout}/composer.lock", "locked dependencies\n");
    file_put_contents("{$checkout}/.env", "APP_KEY=base64:fixture\n");
    file_put_contents("{$checkout}/artisan", '');

    file_put_contents("{$root}/bin/git", <<<'BASH'
        #!/usr/bin/env bash
        set -euo pipefail
        printf '%s\n' "$*" >> "$SAMPLE_GIT_COMMANDS"
        case "$*" in
          *'remote get-url origin'*) printf '%s\n' "$SAMPLE_REMOTE_URL" ;;
          *'cat-file -e '*) [[ "$SAMPLE_COMMIT_PRESENT" == 1 ]] ;;
          *'fetch --quiet origin '*) touch "$SAMPLE_FETCHED" ;;
          *'rev-parse HEAD'*) printf '%s\n' "$SAMPLE_HEAD_SHA" ;;
        esac
        BASH);
    file_put_contents("{$root}/bin/composer", <<<'BASH'
        #!/usr/bin/env bash
        set -euo pipefail
        printf '%s\n' "$*" >> "$SAMPLE_COMPOSER_COMMANDS"
        [[ "$SAMPLE_COMPOSER_FAILS" != 1 ]]
        for argument in "$@"; do
          case "$argument" in --working-dir=*) checkout=${argument#--working-dir=} ;; esac
        done
        if [[ "$SAMPLE_COMPOSER_WRITES_AUTOLOAD" == 1 ]]; then
          mkdir -p "$checkout/vendor"
          printf 'autoloaded\n' > "$checkout/vendor/autoload.php"
        fi
        if [[ "$SAMPLE_COMPOSER_WRITES_LOCK" == 1 ]]; then
          printf 'resolved dependencies\n' > "$checkout/composer.lock"
        fi
        BASH);
    chmod("{$root}/bin/git", 0o700);
    chmod("{$root}/bin/composer", 0o700);

    $source = file_get_contents(dirname(__DIR__, 3).'/resources/guest/converge-sample-app.sh');
    $script = str_replace(
        'checkouts=(/home/orbit/apps/laravel /home/orbit/.orbit/worktrees/laravel/e2e)',
        "checkouts=({$checkout})",
        $source,
    );
    file_put_contents("{$root}/converge.sh", $script);
    chmod("{$root}/converge.sh", 0o700);
    $sha = str_repeat('b', 40);

    return [
        'root' => $root,
        'checkout' => $checkout,
        'script' => "{$root}/converge.sh",
        'environment' => [
            'PATH' => "{$root}/bin:".getenv('PATH'),
            'SAMPLE_COMMIT_PRESENT' => $commitPresent ? '1' : '0',
            'SAMPLE_COMPOSER_COMMANDS' => "{$root}/composer-commands",
            'SAMPLE_COMPOSER_FAILS' => '0',
            'SAMPLE_COMPOSER_WRITES_AUTOLOAD' => '1',
            'SAMPLE_COMPOSER_WRITES_LOCK' => '0',
            'SAMPLE_FETCHED' => "{$root}/fetched",
            'SAMPLE_GIT_COMMANDS' => "{$root}/git-commands",
            'SAMPLE_HEAD_SHA' => $sha,
            'SAMPLE_REMOTE_URL' => 'https://github.com/laravel/laravel.git',
        ],
    ];
}

/** @return array{environment: array<string, string>} */
function internal_tls_fixture(string $root): array
{
    $version = "{$root}/etc/caddy/orbit-versions/1234567890abcdef";
    mkdir("{$version}/fragments", 0o700, true);
    mkdir("{$root}/state", 0o700, true);
    mkdir("{$root}/bin", 0o700, true);
    file_put_contents("{$version}/Caddyfile", "import {$version}/fragments/*.caddy\n");
    file_put_contents("{$version}/fragments/app-prod.caddy", "laravel.internal {\n}\n");
    file_put_contents("{$root}/etc/caddy/orbit-e2e-global.caddy", "{\n    local_certs\n}\n");
    file_put_contents("{$root}/bin/systemctl", "#!/usr/bin/env bash\nprintf '%s\\n' \"\$*\" >>'{$root}/reloads'\n");
    file_put_contents(
        "{$root}/bin/caddy",
        "#!/usr/bin/env bash\nset -euo pipefail\nprintf '%s\\n' \"\$*\" >>'{$root}/validations'\n[[ -f \"\$3\" ]]\n",
    );
    file_put_contents("{$root}/bin/id", "#!/usr/bin/env bash\necho 0\n");
    chmod("{$root}/bin/systemctl", 0o700);
    chmod("{$root}/bin/caddy", 0o700);
    chmod("{$root}/bin/id", 0o700);
    file_put_contents("{$root}/converge.sh", str_replace(
        ['/etc/caddy', '/var/lib/orbit-e2e'],
        ["{$root}/etc/caddy", "{$root}/state"],
        (string) file_get_contents(dirname(__DIR__, 3).'/resources/guest/converge-sample-app.sh'),
    ));

    return ['environment' => ['PATH' => "{$root}/bin:".getenv('PATH')]];
}

/** @return array{root:string,script:string,state:string,commands:string,caddy:string,ca:string} */
function convergence_app_fixture(string $scriptName): array
{
    $root = temporaryPath('orbit-'.$scriptName.'-', 4);
    $checkout = "{$root}/checkout/apps/gateway";
    $orbitHome = "{$root}/orbit-home";
    $state = "{$root}/state";
    $caddy = "{$root}/etc/caddy";
    mkdir("{$root}/bin", 0o700, true);
    mkdir($checkout, 0o700, true);
    mkdir("{$orbitHome}/ssh", 0o700, true);
    mkdir($caddy, 0o700, true);
    mkdir($state, 0o700, true);
    file_put_contents("{$checkout}/artisan", '');
    file_put_contents("{$orbitHome}/ssh/id_ed25519", 'private');
    file_put_contents("{$orbitHome}/ssh/known_hosts", '');
    file_put_contents("{$caddy}/Caddyfile", "example.com {\n}\n");
    file_put_contents(
        "{$root}/bin/ssh-keyscan",
        "#!/usr/bin/env bash\nprintf '%s\\n' 'host ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOJgN5jVtcfw7oASD2F6If4O5mQ/HZBqbrw4QC9PcHEO'\n",
    );
    file_put_contents(
        "{$root}/bin/ssh-keygen",
        "#!/usr/bin/env bash\ncat >/dev/null\nprintf '256 SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA host (ED25519)\\n'\n",
    );
    file_put_contents("{$root}/bin/php", str_replace('__COMMANDS__', "{$root}/commands", <<<'BASH'
        #!/usr/bin/env bash
        printf '%s
        ' "$*" >> '__COMMANDS__'
        if [[ "$*" == *'SELECT COUNT(*) FROM nodes'* ]]; then
          printf '%s\n' "${ORBIT_PHP_NODE_ACTIVE:-0}"
          exit 0
        fi
        case ",${ORBIT_PHP_FAIL:-}," in
          *",provision,"*) [[ "$*" == *' orbit:node-provision '* ]] && exit 1 ;;
        esac
        BASH));
    file_put_contents("{$root}/bin/sudo", <<<'BASH'
        #!/usr/bin/env bash
        set -euo pipefail
        while (($# > 0)); do
          case "$1" in
            -u) shift 2 ;;
            --) shift ;;
            env) shift; while (($# > 0)) && [[ "$1" == *=* ]]; do export "$1"; shift; done; exec "$@" ;;
            *) exec "$@" ;;
          esac
        done
        BASH);
    file_put_contents("{$root}/bin/ssh", str_replace(
        ['__COMMANDS__', '__CA__'],
        ["{$root}/commands", "{$root}/ca-path"],
        <<<'BASH'
            #!/usr/bin/env bash
            printf '%s
            ' "ssh:$*" >> '__COMMANDS__'
            cat >/dev/null
            printf '%s\n' '__CA__'
            BASH,
    ));
    file_put_contents("{$root}/bin/caddy", "#!/usr/bin/env bash\nexit 0\n");
    file_put_contents(
        "{$root}/bin/systemctl",
        str_replace(
            '__COMMANDS__',
            "{$root}/commands",
            "#!/usr/bin/env bash\nprintf '%s\\n' \"systemctl:$*\" >> '__COMMANDS__'\n",
        ),
    );
    file_put_contents("{$root}/bin/tee", "#!/usr/bin/env bash\ncat > /dev/null\n");
    foreach (['ssh-keyscan', 'ssh-keygen', 'php', 'sudo', 'ssh', 'caddy', 'systemctl', 'tee'] as $command) {
        chmod("{$root}/bin/{$command}", 0o700);
    }

    $source = file_get_contents(dirname(__DIR__, 3)."/resources/guest/{$scriptName}");
    $script = str_replace(
        [
            '/var/lib/orbit-e2e',
            '/home/orbit/orbit',
            '/home/orbit/.orbit',
            '/etc/caddy',
            '/var/lib/caddy/.local/share/caddy/pki/authorities/local/root.crt',
        ],
        [$state, "{$root}/checkout", $orbitHome, $caddy, "{$root}/ca-root.crt"],
        $source,
    );
    $path = "{$root}/{$scriptName}";
    file_put_contents($path, $script);
    chmod($path, 0o700);

    return [
        'root' => $root,
        'script' => $path,
        'state' => $state,
        'commands' => "{$root}/commands",
        'caddy' => $caddy,
        'ca' => "{$root}/ca-path",
    ];
}

/**
 * Runs the `https.gateway-internal` probe against a fake resolver that drops
 * every query until it has been asked $blockedTries times.
 *
 * @return array{exit:int,output:string,dig_calls:string,sleeps:list<string>,curl:?string}
 */
function vpn_dns_probe_run(int $blockedTries): array
{
    $root = temporaryPath('orbit-verifier-dns-', 4);
    mkdir("{$root}/bin", 0o700, true);
    file_put_contents("{$root}/bin/dig", <<<BASH
        #!/usr/bin/env bash
        count=\$(( \$(cat '{$root}/dig-calls' 2>/dev/null || printf 0) + 1 ))
        printf '%s' "\$count" > '{$root}/dig-calls'
        if (( count <= {$blockedTries} )); then
          printf ';; connection timed out; no servers could be reached\n' >&2
          exit 9
        fi
        printf '10.44.0.1\n'
        BASH);
    file_put_contents("{$root}/bin/sleep", "#!/usr/bin/env bash\nprintf '%s\\n' \"\$1\" >> '{$root}/sleeps'\n");
    file_put_contents("{$root}/bin/curl", "#!/usr/bin/env bash\nprintf '%s\\n' \"\$*\" > '{$root}/curl'\n");
    foreach (['dig', 'sleep', 'curl'] as $command) {
        chmod("{$root}/bin/{$command}", 0o700);
    }

    $process = new Process([
        'bash',
        dirname(__DIR__, 3).'/resources/guest/verify-topology.sh',
        'https.gateway-internal',
        'readiness',
        str_repeat('a', 40),
        'orbit-e2e-standby-app-dev',
    ], env: ['PATH' => "{$root}/bin:".getenv('PATH')]);

    try {
        $exit = $process->run();

        return [
            'exit' => $exit,
            'output' => $process->getOutput(),
            'dig_calls' => (string) file_get_contents("{$root}/dig-calls"),
            'sleeps' => file_exists("{$root}/sleeps") ? file("{$root}/sleeps", FILE_IGNORE_NEW_LINES) : [],
            'curl' => file_exists("{$root}/curl") ? (string) file_get_contents("{$root}/curl") : null,
        ];
    } finally {
        new Illuminate\Filesystem\Filesystem()->deleteDirectory($root);
    }
}

describe('Gateway host prerequisite convergence', function () {
    it('skips apt when all Gateway prerequisites are installed', function () {
        $root = gateway_prerequisite_fixture();
        try {
            $process = new Process(['bash', "{$root}/converge-gateway.sh", 'prerequisites'], env: [
                'PATH' => "{$root}/bin:".getenv('PATH'),
            ]);
            expect($process->run())->toBe(0)->and(file_exists("{$root}/apt"))->toBeFalse();
        } finally {
            new Illuminate\Filesystem\Filesystem()->deleteDirectory($root);
        }
    });

    it('installs only the missing Gateway prerequisite', function () {
        $root = gateway_prerequisite_fixture();
        try {
            $process = new Process(['bash', "{$root}/converge-gateway.sh", 'prerequisites'], env: [
                'PATH' => "{$root}/bin:".getenv('PATH'),
                'DPKG_MISSING_PACKAGES' => 'php8.5-fpm',
            ]);
            expect($process->run())
                ->toBe(0)
                ->and(file("{$root}/apt", FILE_IGNORE_NEW_LINES))
                ->toBe([
                    'noninteractive update',
                    'noninteractive install --yes --no-install-recommends -- php8.5-fpm',
                ]);
        } finally {
            new Illuminate\Filesystem\Filesystem()->deleteDirectory($root);
        }
    });

    it('installs all Gateway prerequisites in fixed order', function () {
        $root = gateway_prerequisite_fixture();
        try {
            $process = new Process(['bash', "{$root}/converge-gateway.sh", 'prerequisites'], env: [
                'PATH' => "{$root}/bin:".getenv('PATH'),
                'DPKG_MISSING_PACKAGES' => 'caddy,dnsmasq,php8.5-fpm',
            ]);
            expect($process->run())
                ->toBe(0)
                ->and(file("{$root}/apt", FILE_IGNORE_NEW_LINES))
                ->toBe([
                    'noninteractive update',
                    'noninteractive install --yes --no-install-recommends -- caddy dnsmasq php8.5-fpm',
                ]);
        } finally {
            new Illuminate\Filesystem\Filesystem()->deleteDirectory($root);
        }
    });
});

/**
 * A verifier script with the gateway database, SSH material, and the `php`,
 * `wg`, `sudo`, and `ssh` commands faked under one root. `wg` routes app-dev
 * only, so a run that declares app-prod finds one route missing.
 */
function verifierWireguardFixture(): string
{
    $root = temporaryPath('orbit-verifier-wireguard-', 4);
    mkdir("{$root}/bin", 0o700, true);
    mkdir("{$root}/home/orbit/.orbit/ssh", 0o700, true);
    file_put_contents("{$root}/gateway.sqlite", 'fixture');
    file_put_contents("{$root}/home/orbit/.orbit/ssh/id_ed25519", 'fixture');
    file_put_contents("{$root}/home/orbit/.orbit/ssh/known_hosts", 'fixture');
    file_put_contents("{$root}/bin/php", <<<'BASH'
        #!/usr/bin/env bash
        case "$*" in
          *app-dev*) printf '10.0.0.10\n' ;;
          *app-prod*) printf '10.0.0.20\n' ;;
          *) exit 1 ;;
        esac
        BASH);
    file_put_contents("{$root}/bin/wg", <<<'BASH'
        #!/usr/bin/env bash
        printf '%s\n' 'peer-dev 10.0.0.10/32' 'peer-unrelated 10.0.0.99/32'
        BASH);
    file_put_contents("{$root}/bin/sudo", <<<'BASH'
        #!/usr/bin/env bash
        shift 3
        exec "$@"
        BASH);
    file_put_contents("{$root}/bin/ssh", "#!/usr/bin/env bash\nprintf 'ssh-reached\\n' >> '{$root}/ssh-reached'\n");
    foreach (['php', 'wg', 'sudo', 'ssh'] as $command) {
        chmod("{$root}/bin/{$command}", 0o700);
    }
    $script = str_replace(
        [
            '/home/orbit/.orbit/gateway.sqlite',
            '/home/orbit/.orbit/ssh/id_ed25519',
            '/home/orbit/.orbit/ssh/known_hosts',
        ],
        [
            "{$root}/gateway.sqlite",
            "{$root}/home/orbit/.orbit/ssh/id_ed25519",
            "{$root}/home/orbit/.orbit/ssh/known_hosts",
        ],
        (string) file_get_contents(dirname(__DIR__, 3).'/resources/guest/verify-topology.sh'),
    );
    file_put_contents("{$root}/verify-topology.sh", $script);
    chmod("{$root}/verify-topology.sh", 0o700);

    return $root;
}

/**
 * The reachability probe of one fixture root, for the given declared peers.
 *
 * @param list<string> $peers
 */
function verifierWireguardProcess(string $root, array $peers): Process
{
    return new Process([
        'bash',
        "{$root}/verify-topology.sh",
        'wireguard.reachability',
        'readiness',
        str_repeat('a', 40),
        'orbit-e2e-standby-gateway',
        ...$peers,
    ], env: ['PATH' => "{$root}/bin:".getenv('PATH')]);
}

describe('convergence guest scripts', function () {
    it('runs Gateway Artisan commands from the Gateway checkout', function () {
        $root = temporaryPath('orbit-gateway-converge-', 4);
        try {
            mkdir("{$root}/bin", 0o700, true);
            mkdir("{$root}/checkout/apps/gateway", 0o700, true);
            file_put_contents("{$root}/bin/sudo", <<<'BASH'
                #!/usr/bin/env bash
                shift 3
                exec "$@"
                BASH);
            file_put_contents("{$root}/bin/install", <<<'BASH'
                #!/usr/bin/env bash
                mkdir -p "${@: -1}"
                BASH);
            file_put_contents("{$root}/bin/chown", "#!/usr/bin/env bash\nexit 0\n");
            file_put_contents("{$root}/bin/dpkg-query", "#!/usr/bin/env bash\nprintf 'install ok installed'\n");
            file_put_contents("{$root}/bin/apt-get", "#!/usr/bin/env bash\nexit 0\n");
            file_put_contents("{$root}/bin/php", str_replace(
                '__CWD_FILE__',
                "{$root}/cwd",
                <<<'BASH'
                    #!/usr/bin/env bash
                    printf '%s\n' "$PWD" >> '__CWD_FILE__'
                    BASH,
            ));
            foreach (['sudo', 'install', 'chown', 'dpkg-query', 'apt-get', 'php'] as $command) {
                chmod("{$root}/bin/{$command}", 0o700);
            }

            $source = str_replace(
                ['/home/orbit/orbit', '/home/orbit/.orbit'],
                ["{$root}/checkout", "{$root}/orbit"],
                file_get_contents(dirname(__DIR__, 3).'/resources/guest/converge-gateway.sh'),
            );
            file_put_contents("{$root}/converge-gateway.sh", $source);
            chmod("{$root}/converge-gateway.sh", 0o700);

            $process = new Process(['bash', "{$root}/converge-gateway.sh", 'bootstrap', 'gateway'], env: [
                'PATH' => "{$root}/bin:".getenv('PATH'),
            ]);
            expect($process->run())->toBe(0);

            expect(file("{$root}/cwd", FILE_IGNORE_NEW_LINES))->toBe([
                "{$root}/checkout/apps/gateway",
                "{$root}/checkout/apps/gateway",
            ]);
        } finally {
            new Illuminate\Filesystem\Filesystem()->deleteDirectory($root);
        }
    });

    it('keeps every guest script valid Bash', function () {
        $scripts = glob(dirname(__DIR__, 3).'/resources/guest/*.sh');
        expect($scripts)->not->toBeFalse()->not->toBeEmpty();

        foreach ($scripts as $script) {
            expect(new Process(['bash', '-n', $script], timeout: 5)->run())->toBe(0, $script);
        }
    });

    it('provisions app-dev when the Gateway store has no active node role', function (): void {
        $fixture = convergence_app_fixture('converge-app-dev.sh');
        file_put_contents("{$fixture['root']}/orbit-home/gateway.sqlite", 'fixture');

        try {
            $process = new Process(['bash', $fixture['script'], 'app-dev', '192.0.2.11', 'x86_64'], env: [
                'PATH' => "{$fixture['root']}/bin:".getenv('PATH'),
            ]);

            expect($process->run())
                ->toBe(0)
                ->and(file_get_contents($fixture['commands']))
                ->toContain('orbit:node-provision app-dev 192.0.2.11')
                ->toContain('--wireguard-address=10.44.0.2')
                ->not->toContain('orbit:node-retarget');
        } finally {
            new Illuminate\Filesystem\Filesystem()->deleteDirectory($fixture['root']);
        }
    });

    it('skips provisioning when the Gateway store already holds the active node role', function (): void {
        $fixture = convergence_app_fixture('converge-app-dev.sh');
        file_put_contents("{$fixture['root']}/orbit-home/gateway.sqlite", 'fixture');

        try {
            $process = new Process(['bash', $fixture['script'], 'app-dev', '198.51.100.11', 'x86_64'], env: [
                'PATH' => "{$fixture['root']}/bin:".getenv('PATH'),
                'ORBIT_PHP_NODE_ACTIVE' => '1',
            ]);

            expect($process->run())
                ->toBe(0)
                ->and(file_get_contents($fixture['commands']))
                ->not->toContain('orbit:node-provision');
        } finally {
            new Illuminate\Filesystem\Filesystem()->deleteDirectory($fixture['root']);
        }
    });

    it('fails closed when provisioning fails', function (): void {
        $fixture = convergence_app_fixture('converge-app-dev.sh');

        try {
            $process = new Process(['bash', $fixture['script'], 'app-dev', '192.0.2.11', 'x86_64'], env: [
                'PATH' => "{$fixture['root']}/bin:".getenv('PATH'),
                'ORBIT_PHP_FAIL' => 'provision',
            ]);

            expect($process->run())->not->toBe(0);
        } finally {
            new Illuminate\Filesystem\Filesystem()->deleteDirectory($fixture['root']);
        }
    });

    it('repairs the node WireGuard endpoint only when the Gateway address changed', function (): void {
        $root = temporaryPath('orbit-retarget-vpn-', 4);
        mkdir("{$root}/bin", 0o700, true);
        mkdir("{$root}/etc/wireguard", 0o700, true);
        file_put_contents(
            "{$root}/etc/wireguard/orbit.conf",
            "[Interface]\nPrivateKey = x\nAddress = 10.44.0.2/24\n\n[Peer]\nPublicKey = FEfGSTB1q+e0ZqVAOz7SYf7ry6NiZDZxkvMlQS4QJ0w=\nEndpoint = 10.232.1.10:51820\nAllowedIPs = 10.44.0.0/24\n",
        );
        file_put_contents(
            "{$root}/bin/systemctl",
            "#!/usr/bin/env bash\nprintf '%s\\n' \"\$*\" >> '{$root}/commands'\n"
            ."[[ \"\$1\" == is-active ]] && exit \"\${ORBIT_WG_INACTIVE:-0}\"\nexit 0\n",
        );
        file_put_contents("{$root}/bin/wg", "#!/usr/bin/env bash\nprintf '%s\\n' \"wg \$*\" >> '{$root}/commands'\n");
        file_put_contents("{$root}/bin/ping", "#!/usr/bin/env bash\nexit 0\n");
        chmod("{$root}/bin/systemctl", 0o700);
        chmod("{$root}/bin/wg", 0o700);
        chmod("{$root}/bin/ping", 0o700);
        $script = str_replace(
            '/etc/wireguard/orbit.conf',
            "{$root}/etc/wireguard/orbit.conf",
            file_get_contents(dirname(__DIR__, 3).'/resources/guest/retarget-vpn.sh'),
        );
        file_put_contents("{$root}/retarget-vpn.sh", $script);
        $environment = ['PATH' => "{$root}/bin:".getenv('PATH')];

        try {
            expect(new Process(['bash', "{$root}/retarget-vpn.sh", '10.232.1.10'], env: $environment)->run())
                ->toBe(0)
                ->and(file("{$root}/commands", FILE_IGNORE_NEW_LINES))
                ->toBe(['is-active --quiet wg-quick@orbit']);
            unlink("{$root}/commands");

            expect(new Process(['bash', "{$root}/retarget-vpn.sh", '10.232.7.10'], env: $environment)->run())
                ->toBe(0)
                ->and(file("{$root}/commands", FILE_IGNORE_NEW_LINES))
                ->toBe([
                    'is-active --quiet wg-quick@orbit',
                    'wg set orbit peer FEfGSTB1q+e0ZqVAOz7SYf7ry6NiZDZxkvMlQS4QJ0w= endpoint 10.232.7.10:51820',
                ])
                ->and(file_get_contents("{$root}/etc/wireguard/orbit.conf"))
                ->toContain("Endpoint = 10.232.7.10:51820\n")
                ->and(fileperms("{$root}/etc/wireguard/orbit.conf") & 0o777)
                ->toBe(0o600);

            expect(new Process(['bash', "{$root}/retarget-vpn.sh", 'not-an-address'], env: $environment)->run())
                ->toBe(64);

            unlink("{$root}/commands");
            expect(
                new Process(
                    ['bash', "{$root}/retarget-vpn.sh", '10.232.9.10'],
                    env: [...$environment, 'ORBIT_WG_INACTIVE' => '3'],
                )->run(),
            )
                ->toBe(0)
                ->and(file("{$root}/commands", FILE_IGNORE_NEW_LINES))
                ->toBe(['is-active --quiet wg-quick@orbit', 'restart wg-quick@orbit']);
        } finally {
            new Illuminate\Filesystem\Filesystem()->deleteDirectory($root);
        }
    });

    it('exits cleanly on an unprovisioned node without a WireGuard config', function (): void {
        $root = temporaryPath('orbit-retarget-vpn-', 4);
        mkdir($root, 0o700, true);
        $script = str_replace(
            '/etc/wireguard/orbit.conf',
            "{$root}/missing.conf",
            file_get_contents(dirname(__DIR__, 3).'/resources/guest/retarget-vpn.sh'),
        );
        file_put_contents("{$root}/retarget-vpn.sh", $script);

        try {
            expect(new Process(['bash', "{$root}/retarget-vpn.sh", '10.232.1.10'])->run())->toBe(0);
        } finally {
            new Illuminate\Filesystem\Filesystem()->deleteDirectory($root);
        }
    });

    it('always reconciles app-prod caddy after provisioning', function (): void {
        $fixture = convergence_app_fixture('converge-app-prod-internal-tls.sh');

        try {
            $process = new Process(['bash', $fixture['script'], 'app-prod', '192.0.2.12', 'aarch64'], env: [
                'PATH' => "{$fixture['root']}/bin:".getenv('PATH'),
            ]);
            expect($process->run())
                ->toBe(0)
                ->and(file_get_contents($fixture['commands']))
                ->toContain('orbit:node-provision')
                ->toContain('ssh:-i');
        } finally {
            new Illuminate\Filesystem\Filesystem()->deleteDirectory($fixture['root']);
        }
    });

    it('keeps the product-managed Caddyfile and places internal TLS as an unmanaged fragment', function (): void {
        $guest = dirname(__DIR__, 3).'/resources/guest';
        $production = file_get_contents("{$guest}/converge-app-prod-internal-tls.sh");
        $sample = file_get_contents("{$guest}/converge-sample-app.sh");

        expect($production)
            ->toContain('orbit-e2e-global.caddy', 'local_certs', 'caddy-ca-path')
            ->not->toContain('readlink -f')->and($production)
            ->not->toContain('systemctl reload caddy')->and($sample)->toContain(
                'internal-tls)',
                'fragments/00-orbit-e2e-global.caddy',
                'readlink -f "$live"',
                'caddy validate --config "$live" --adapter caddyfile',
                'systemctl reload caddy',
            )
            ->not->toContain('ln -sfn Caddyfile.orbit-e2e', 'caddy-rendered-path"', 'unwrap-caddy');
    });

    it('enforces the verifier argument and output contract', function () {
        $script = dirname(__DIR__, 3).'/resources/guest/verify-topology.sh';
        $sha = str_repeat('a', 40);
        expect(new Process(['bash', $script], timeout: 5)->run())->not->toBe(0);
        foreach ([
            ['vm.gateway.running', 'bad',       $sha],
            ['vm.gateway.running', 'readiness', 'bad'],
            ['unknown',            'readiness', $sha],
        ] as $args) {
            $process = new Process(['bash', $script, ...$args]);
            expect($process->run())->not->toBe(0)->and(trim($process->getOutput()))->toBe('');
        }
        $instance = 'orbit-e2e-standby-gateway';
        // Only the two fleet probes take a declared topology, and only in the shape they take.
        foreach ([
            ['vm.gateway.running', 'readiness', $sha, $instance, 'gateway,app-dev'],
            ['role.gateway', 'readiness', $sha, $instance, 'gateway,app-dev'],
            ['wireguard.reachability', 'readiness', $sha, $instance],
            ['role.assignments', 'readiness', $sha, $instance, 'gateway,app-dev', 'extra'],
            ['role.assignments', 'readiness', $sha, $instance, 'Gateway'],
            ['role.assignments', 'readiness', $sha, $instance, 'gateway,'],
            ['role.assignments', 'readiness', $sha, $instance, 'gateway app-dev'],
        ] as $args) {
            $process = new Process(['bash', $script, ...$args]);
            expect($process->run())->not->toBe(0)->and(trim($process->getOutput()))->toBe('');
        }
    });

    it('returns complete structured evidence for a command-only VM probe', function () {
        $root = temporaryPath('orbit-verifier-', 4);
        mkdir("{$root}/bin", 0o700, true);
        file_put_contents("{$root}/bin/systemctl", "#!/usr/bin/env bash\nprintf 'running\\n'\n");
        chmod("{$root}/bin/systemctl", 0o700);
        $sha = str_repeat('a', 40);
        $process = new Process([
            'bash',
            dirname(__DIR__, 3).'/resources/guest/verify-topology.sh',
            'vm.gateway.running',
            'readiness',
            $sha,
            'orbit-e2e-standby-gateway',
        ], env: ['PATH' => "{$root}/bin:".getenv('PATH')]);
        $evidence = json_decode($process->mustRun()->getOutput(), true, 16, JSON_THROW_ON_ERROR);
        expect($evidence)
            ->toMatchArray([
                'probe' => 'vm.gateway.running',
                'passed' => true,
                'identity' => $sha,
                'expected' => 'running|degraded',
                'observed' => 'running',
                'evidence_ref' => 'incus://orbit-e2e-standby-gateway/vm.gateway.running',
            ])
            ->and($evidence['checked_at'] ?? null)
            ->toMatch('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D');
    });

    it('accepts degraded system state when systemctl returns a nonzero status', function () {
        $root = temporaryPath('orbit-verifier-degraded-', 4);
        mkdir("{$root}/bin", 0o700, true);
        file_put_contents("{$root}/bin/systemctl", "#!/usr/bin/env bash\nprintf 'degraded\\n'\nexit 1\n");
        chmod("{$root}/bin/systemctl", 0o700);
        $process = new Process([
            'bash',
            dirname(__DIR__, 3).'/resources/guest/verify-topology.sh',
            'vm.gateway.running',
            'readiness',
            str_repeat('a', 40),
            'orbit-e2e-standby-gateway',
        ], env: ['PATH' => "{$root}/bin:".getenv('PATH')]);
        try {
            $evidence = json_decode($process->mustRun()->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            expect($evidence['observed'])->toBe('degraded');
        } finally {
            new Illuminate\Filesystem\Filesystem()->deleteDirectory($root);
        }
    });

    it('verifies the exact source manifest and effective guest tree', function () {
        $root = temporaryPath('orbit-verifier-source-', 4);
        $repository = "{$root}/orbit";
        mkdir($repository, 0o700, true);

        try {
            new Process(['git', '-C', $repository, 'init', '--quiet'])->mustRun();
            new Process(['git', '-C', $repository, 'config', 'user.name', 'Orbit E2E'])->mustRun();
            new Process(['git', '-C', $repository, 'config', 'user.email', 'e2e@orbit.invalid'])->mustRun();
            file_put_contents("{$repository}/tracked.txt", "base\n");
            new Process(['git', '-C', $repository, 'add', 'tracked.txt'])->mustRun();
            new Process(['git', '-C', $repository, 'commit', '--quiet', '-m', 'base'])->mustRun();
            $sha = trim(new Process(['git', '-C', $repository, 'rev-parse', 'HEAD'])->mustRun()->getOutput());

            file_put_contents("{$repository}/tracked.txt", "feature\n");
            $manifest = "tracked.txt\0";
            file_put_contents("{$repository}/.git/orbit-overlay.paths", $manifest);

            $index = "{$root}/index";
            $environment = ['GIT_INDEX_FILE' => $index];
            new Process(['git', '-C', $repository, 'read-tree', 'HEAD'], env: $environment)->mustRun();
            new Process(['git', '-C', $repository, 'add', '-A', '--', '.'], env: $environment)->mustRun();
            $tree = trim(
                new Process(['git', '-C', $repository, 'write-tree'], env: $environment)->mustRun()->getOutput(),
            );
            $treeHash = hash('sha256', $tree);
            file_put_contents(
                "{$repository}/.git/orbit-source-state",
                json_encode(['sha' => $sha, 'tree' => $treeHash], JSON_THROW_ON_ERROR)."\n",
            );

            $script = str_replace(
                '/home/orbit/orbit',
                $repository,
                file_get_contents(dirname(__DIR__, 3).'/resources/guest/verify-topology.sh'),
            );
            file_put_contents("{$root}/verify.sh", $script);
            chmod("{$root}/verify.sh", 0o700);
            $command = [
                'bash',
                "{$root}/verify.sh",
                'source.manifest',
                'proof',
                $sha,
                'orbit-e2e-standby-gateway',
                $treeHash,
                base64_encode($manifest),
            ];

            $evidence = json_decode(
                new Process($command)->mustRun()->getOutput(),
                true,
                16,
                JSON_THROW_ON_ERROR,
            );
            expect($evidence)
                ->toMatchArray([
                    'expected' => $sha.':'.$treeHash,
                    'observed' => $sha.':'.$treeHash,
                ]);

            file_put_contents("{$repository}/.git/orbit-overlay.paths", "wrong.txt\0");
            expect(new Process($command)->run())->not->toBe(0);

            file_put_contents("{$repository}/.git/orbit-overlay.paths", $manifest);
            file_put_contents("{$repository}/tracked.txt", "drift\n");
            expect(new Process($command)->run())->not->toBe(0);
        } finally {
            new Illuminate\Filesystem\Filesystem()->deleteDirectory($root);
        }
    });

    it('accepts degraded system state but rejects other non-failed states', function () {
        $root = temporaryPath('orbit-verifier-state-', 4);
        mkdir("{$root}/bin", 0o700, true);
        file_put_contents("{$root}/bin/systemctl", "#!/usr/bin/env bash\nprintf '%s\\n' \"\$SYSTEM_STATE\"\n");
        chmod("{$root}/bin/systemctl", 0o700);
        $script = dirname(__DIR__, 3).'/resources/guest/verify-topology.sh';
        $sha = str_repeat('a', 40);

        $degraded = new Process([
            'bash',
            $script,
            'vm.gateway.running',
            'readiness',
            $sha,
            'orbit-e2e-standby-gateway',
        ], env: [
            'PATH' => "{$root}/bin:".getenv('PATH'),
            'SYSTEM_STATE' => 'degraded',
        ]);
        expect($degraded->run())->toBe(0);

        $starting = new Process([
            'bash',
            $script,
            'vm.gateway.running',
            'readiness',
            $sha,
            'orbit-e2e-standby-gateway',
        ], env: [
            'PATH' => "{$root}/bin:".getenv('PATH'),
            'SYSTEM_STATE' => 'starting',
        ]);
        expect($starting->run())->not->toBe(0);
    });

    it('keeps the CLI gateway status check for the app-dev operator only', function () {
        $source = file_get_contents(dirname(__DIR__, 3).'/resources/guest/verify-topology.sh');
        preg_match('/role\.gateway\) (.*?);/s', $source, $gatewayBranch);
        expect($gatewayBranch[1] ?? '')
            ->not
            ->toContain('gateway:status')
            ->and($source)
            ->toContain('operator.app-dev) sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit');
    });

    it('emits no JSON when an observable command fails', function () {
        $root = temporaryPath('orbit-verifier-fail-', 4);
        mkdir("{$root}/bin", 0o700, true);
        file_put_contents("{$root}/bin/systemctl", "#!/usr/bin/env bash\nexit 1\n");
        chmod("{$root}/bin/systemctl", 0o700);
        $process = new Process([
            'bash',
            dirname(__DIR__, 3).'/resources/guest/verify-topology.sh',
            'vm.gateway.running',
            'readiness',
            str_repeat('a', 40),
            'orbit-e2e-standby-gateway',
        ], env: ['PATH' => "{$root}/bin:".getenv('PATH')]);
        expect($process->run())->not->toBe(0)->and(trim($process->getOutput()))->toBe('');
    });

    it('proves each peer the plan declares', function () {
        $root = verifierWireguardFixture();
        // The fixture routes app-dev only, so a declaration that kept app-dev alone passes.
        $process = verifierWireguardProcess($root, ['app-dev']);
        $exit = $process->run();
        $evidence = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);

        expect($exit)
            ->toBe(0)
            ->and($evidence['probe'] ?? null)
            ->toBe('wireguard.reachability')
            ->and($evidence['expected'] ?? null)
            ->toBe('app-dev:wireguard-route+ssh')
            ->and(file("{$root}/ssh-reached"))
            ->toHaveCount(1);
    });

    it('fails wireguard reachability before SSH when one declared route is missing', function () {
        $root = verifierWireguardFixture();
        $process = verifierWireguardProcess($root, ['app-dev', 'app-prod']);

        expect($process->run())
            ->not
            ->toBe(0)
            ->and(trim($process->getOutput()))
            ->toBe('')
            ->and(file_exists("{$root}/ssh-reached"))
            ->toBeFalse();
    });

    it('retries the VPN DNS probe within the bound and records the tries', function (
        int $blockedTries,
        int $expectedTries,
    ) {
        $run = vpn_dns_probe_run($blockedTries);
        $evidence = json_decode($run['output'], true, 16, JSON_THROW_ON_ERROR);

        expect($run['exit'])
            ->toBe(0)
            ->and($evidence)
            ->toMatchArray([
                'probe' => 'https.gateway-internal',
                'passed' => true,
                'expected' => 'https://gateway.orbit/up:vpn-dns+reachable,tries<=3',
                'observed' => "https://gateway.orbit/up:vpn-dns+reachable,tries={$expectedTries}",
            ])
            ->and($run['dig_calls'])
            ->toBe((string) $expectedTries)
            ->and($run['sleeps'])
            ->toBe(array_fill(0, $expectedTries - 1, '2'))
            ->and($run['curl'])
            ->toContain('--resolve gateway.orbit:443:10.44.0.1 https://gateway.orbit/up');
    })->with([
        'resolver answers at once' => [0, 1],
        'resolver blocked for the first try' => [1, 2],
        'resolver blocked until the last try' => [2, 3],
    ]);

    it('fails the VPN DNS probe with the existing evidence shape when the resolver stays blocked for the full bound', function () {
        $run = vpn_dns_probe_run(3);

        expect($run['exit'])
            ->not
            ->toBe(0)
            ->and(trim($run['output']))
            ->toBe('')
            ->and($run['dig_calls'])
            ->toBe('3')
            ->and($run['sleeps'])
            ->toBe(['2', '2'])
            ->and($run['curl'])
            ->toBeNull();
    });

    it('proves the base control-plane role assignments and accepts active extras', function () {
        $root = temporaryPath('orbit-verifier-roles-', 4);
        mkdir("{$root}/bin", 0o700, true);
        try {
            $db = "{$root}/gateway.sqlite";
            $pdo = new PDO("sqlite:{$db}");
            $pdo->exec('CREATE TABLE nodes (id INTEGER PRIMARY KEY, name TEXT, status TEXT)');
            $pdo->exec('CREATE TABLE node_roles (node_id INTEGER, role TEXT, status TEXT)');
            $pdo->exec(
                "INSERT INTO nodes VALUES (1, 'gateway', 'active'), (2, 'app-dev', 'active'), "
                ."(3, 'app-prod', 'active')",
            );
            $pdo->exec(
                "INSERT INTO node_roles VALUES (1, 'gateway', 'active'), (1, 'vpn', 'active'), "
                ."(2, 'app-dev', 'active'), (3, 'app-prod', 'active')",
            );
            file_put_contents("{$root}/bin/php", "#!/usr/bin/env bash\nexec /usr/bin/php \"\$@\"\n");
            chmod("{$root}/bin/php", 0o700);
            $script = str_replace(
                '/home/orbit/.orbit/gateway.sqlite',
                $db,
                file_get_contents(dirname(__DIR__, 3).'/resources/guest/verify-topology.sh'),
            );
            file_put_contents("{$root}/verify.sh", $script);
            chmod("{$root}/verify.sh", 0o700);
            $command = [
                'bash',
                "{$root}/verify.sh",
                'role.assignments',
                'proof',
                str_repeat('a', 40),
                'orbit-e2e-standby-gateway',
            ];
            $environment = ['PATH' => "{$root}/bin:".getenv('PATH')];

            $evidence = json_decode(
                new Process($command, env: $environment)->mustRun()->getOutput(),
                true,
                16,
                JSON_THROW_ON_ERROR,
            );
            expect($evidence)
                ->toMatchArray([
                    'probe' => 'role.assignments',
                    'passed' => true,
                    'identity' => str_repeat('a', 40),
                    'expected' => 'gateway:gateway+vpn,app-dev:app-dev,app-prod:app-prod:active',
                    'observed' => 'gateway:gateway+vpn,app-dev:app-dev,app-prod:app-prod:active',
                    'evidence_ref' => 'incus://orbit-e2e-standby-gateway/role.assignments',
                ]);

            $pdo->exec("UPDATE node_roles SET status = 'failed' WHERE role = 'app-dev'");
            expect(new Process($command, env: $environment)->run())->not->toBe(0);

            // A proof may add a role. An active extra passes and is named in the evidence.
            $pdo->exec("UPDATE node_roles SET status = 'active' WHERE role = 'app-dev'");
            $pdo->exec("INSERT INTO node_roles VALUES (2, 'metrics', 'active')");
            $withExtra = json_decode(
                new Process($command, env: $environment)->mustRun()->getOutput(),
                true,
                16,
                JSON_THROW_ON_ERROR,
            );
            expect($withExtra)->toMatchArray([
                'passed' => true,
                'observed' => 'gateway:gateway+vpn,app-dev:app-dev,app-prod:app-prod:active+app-dev:metrics',
            ]);

            // An extra that is not active fails, and so does a missing base assignment.
            $pdo->exec("UPDATE node_roles SET status = 'failed' WHERE role = 'metrics'");
            expect(new Process($command, env: $environment)->run())->not->toBe(0);

            $pdo->exec("DELETE FROM node_roles WHERE role = 'metrics'");
            $pdo->exec("UPDATE nodes SET status = 'provisioning' WHERE name = 'app-prod'");
            expect(new Process($command, env: $environment)->run())->not->toBe(0);

            $pdo->exec("UPDATE nodes SET status = 'active' WHERE name = 'app-prod'");
            $pdo->exec("DELETE FROM node_roles WHERE role = 'vpn'");
            expect(new Process($command, env: $environment)->run())->not->toBe(0);
        } finally {
            new Illuminate\Filesystem\Filesystem()->deleteDirectory($root);
        }
    });

    it('keeps verifier contract paths and commands explicit', function () {
        $source = file_get_contents(dirname(__DIR__, 3).'/resources/guest/verify-topology.sh');
        expect($source)->toContain(
            'php8.5-fpm',
            'wg-quick@orbit',
            'wg show orbit',
            'SELECT wireguard_address FROM nodes WHERE name = ?',
            'orbit',
            '/home/orbit/apps/laravel',
            '/home/orbit/.orbit/worktrees/laravel/e2e',
            '/var/www/laravel/e2e-prod',
            'source_root=/home/orbit/orbit',
            'source_marker=/var/lib/orbit-e2e/source-state',
            'orbit-overlay.paths',
            'orbit-source-state',
            'gateway:status',
            'gateway.orbit',
            'caddy-ca-path',
            '--retry 10 --retry-delay 2 --retry-connrefused --retry-all-errors',
            '--resolve laravel.internal:443:127.0.0.1',
            'SELECT wireguard_address FROM nodes',
            '"orbit@$peer_address"',
            'StrictHostKeyChecking=yes',
            'known_hosts=/home/orbit/.orbit/ssh/known_hosts',
            'UserKnownHostsFile="$known_hosts"',
            'echo $address;',
            'dig +time=3 +tries=1 +short gateway.orbit @10.44.0.1',
            'while ((dns_tries < 3)); do',
            'vpn-dns+reachable,tries<=3',
            'vpn-dns+reachable,tries=$dns_tries',
            '--resolve "gateway.orbit:443:$resolved" https://gateway.orbit/up',
            'repo_git git -C "$source_root" rev-parse HEAD',
            'mountpoint -q -- "$source_root"',
            'sha256sum -- "$source_root/.git"',
            'repo_git env GIT_INDEX_FILE="$index" git -C "$repo" write-tree',
        );
        expect($source)
            ->not->toContain('echo $address, "\\n";')
            ->not->toContain('HostKeyAlias=')
            ->not->toContain('sqlite3')
            ->not->toContain('StrictHostKeyChecking=no')->toContain('awk -v expected="$peer_address/32"');
    });
    it('uses fixed paths, safe arguments, idempotent resources, and the stock Laravel repository', function () {
        $guest = dirname(__DIR__, 3).'/resources/guest';
        $scripts = [
            'prepare-node.sh',
            'converge-gateway.sh',
            'converge-app-dev.sh',
            'converge-app-prod-internal-tls.sh',
            'converge-sample-app.sh',
        ];

        foreach ($scripts as $script) {
            $contents = file_get_contents("{$guest}/{$script}");
            expect($contents)->toBeString()->toContain('set -euo pipefail', 'exit 64');
        }

        $prepare = file_get_contents("{$guest}/prepare-node.sh");
        $gateway = file_get_contents("{$guest}/converge-gateway.sh");
        $hydrate = file_get_contents("{$guest}/hydrate-orbit.sh");
        $production = file_get_contents("{$guest}/converge-app-prod-internal-tls.sh");
        $sample = file_get_contents("{$guest}/converge-sample-app.sh");

        expect($prepare)
            ->not->toContain('hydrate-orbit.sh')->and($gateway)->toContain(
                '/home/orbit/orbit',
                '/home/orbit/.orbit/gateway.sqlite',
                'migrate --force',
                'orbit:bootstrap',
                'ORBIT_GATEWAY_CHECKOUT=/home/orbit/orbit/apps/gateway',
                'exit 70',
                'exit 71',
            )
            ->not->toContain('sqlite3 -noheader -separator', '.parameter set :public_host', 'orbit-e2e-failure')
            ->not->toContain('hydrate-orbit.sh')->and($production)->toContain(
                'local_certs',
                'caddy validate',
                'caddy-ca-path',
            )->and($sample)->toContain(
                'https://github.com/laravel/laravel.git',
                'node:access:add',
                'app:new laravel',
                'instance:new',
                'workspace:new',
                'APP_KEY=base64:',
                'composer install',
                'runtime_user=orbit',
                'runtime_user=orbit-laravel',
                'sudo -u "$runtime_user"',
                '/home/orbit/.orbit/e2e-gateway-root-ca.pem',
                '/api/v1/ca/root',
                '$v["data"]["root_ca"]',
                'live=/etc/caddy/Caddyfile',
                'readlink -f "$live"',
                '--cacert "$ca" --resolve laravel.internal:443:127.0.0.1 https://laravel.internal/',
                'artisan" migrate --force --no-interaction',
                'database/database.sqlite',
            )
            ->not->toContain('/api/root-ca')->and($hydrate)->toContain(
                '$ORBIT_HOME/gateway.app-key',
                'openssl rand -base64 32',
                'ORBIT_GATEWAY_CHECKOUT=/home/orbit/orbit/apps/gateway',
                'DB_DATABASE=/home/orbit/.orbit/gateway.sqlite',
            )
            ->not->toContain('AAAAAAAAAAAAAAAA');

        expect($production)
            ->toContain(
                'sudo -u orbit -- env HOME=/home/orbit ssh -i /home/orbit/.orbit/ssh/id_ed25519',
                '-o UserKnownHostsFile=/home/orbit/.orbit/ssh/known_hosts',
                '-o BatchMode=yes',
                '-o StrictHostKeyChecking=yes',
                '-- orbit@"$wireguard_address"',
            )
            ->not->toContain('ssh -o BatchMode=yes -- "$1"');
        expect(file_get_contents("{$guest}/converge-app-dev.sh"))
            ->toContain(
                'cd /home/orbit/orbit/apps/gateway',
                'orbit:node-provision "$1" "$2"',
                'ssh-keyscan -T 5 -t ed25519 -- "$1"',
                'scan_host_key "$2"',
                'SELECT COUNT(*) FROM nodes n INNER JOIN node_roles r ON r.node_id = n.id',
                '[[ "$3" =~ ^(x86_64|aarch64)$ ]]',
                '--architecture="$3"',
                '--user=orbit',
                'wireguard_address=10.44.0.2',
                '--wireguard-address="$wireguard_address"',
            )
            ->not->toContain('uname -m');
        expect($production)
            ->toContain(
                'cd /home/orbit/orbit/apps/gateway',
                'orbit:node-provision "$1" "$2"',
                'ssh-keyscan -T 5 -t ed25519 -- "$1"',
                'scan_host_key "$2"',
                'SELECT COUNT(*) FROM nodes n INNER JOIN node_roles r ON r.node_id = n.id',
                '[[ "$3" =~ ^(x86_64|aarch64)$ ]]',
                '--architecture="$3"',
                '--user=orbit',
                'wireguard_address=10.44.0.3',
                '--wireguard-address="$wireguard_address"',
            )
            ->not->toContain('uname -m');
    });

    it('hydrates Composer dependencies only when the lock marker is stale', function () {
        $source = file_get_contents(dirname(__DIR__, 3).'/resources/guest/hydrate-orbit.sh');

        expect($source)
            ->toContain('hydrate_composer_dependencies()')
            ->toContain('sha256sum "$project_path/composer.lock"')
            ->toContain('vendor/.orbit-e2e-composer-lock')
            ->toContain('vendor/autoload.php')
            ->toContain('mktemp "$project_path/vendor/.orbit-e2e-composer-lock.XXXXXX"')
            ->toContain('mv -f "$marker_tmp" "$marker"')
            ->toContain('hydrate_composer_dependencies "$repo/$project"');
    });

    it('skips sample fetch and Composer hydration when the exact state already exists', function () {
        $fixture = sample_hydration_fixture();
        try {
            file_put_contents("{$fixture['checkout']}/vendor/autoload.php", "autoloaded\n");
            file_put_contents(
                "{$fixture['checkout']}/vendor/.orbit-e2e-composer-lock",
                hash_file('sha256', "{$fixture['checkout']}/composer.lock"),
            );

            $process = new Process(
                ['bash', $fixture['script'], 'hydrate', str_repeat('b', 40), 'app-dev'],
                env: $fixture['environment'],
            );

            expect($process->run())
                ->toBe(0)
                ->and(file("{$fixture['root']}/git-commands", FILE_IGNORE_NEW_LINES))
                ->not
                ->toContain('-C '.$fixture['checkout'].' fetch --quiet origin '.str_repeat('b', 40))
                ->and(file_exists("{$fixture['root']}/composer-commands"))
                ->toBeFalse();
        } finally {
            new Illuminate\Filesystem\Filesystem()->deleteDirectory($fixture['root']);
        }
    });

    it('fetches a missing sample commit before it verifies the exact checkout head', function () {
        $fixture = sample_hydration_fixture(false);
        try {
            file_put_contents("{$fixture['checkout']}/vendor/autoload.php", "autoloaded\n");
            file_put_contents(
                "{$fixture['checkout']}/vendor/.orbit-e2e-composer-lock",
                hash_file('sha256', "{$fixture['checkout']}/composer.lock"),
            );

            $process = new Process(
                ['bash', $fixture['script'], 'hydrate', str_repeat('b', 40), 'app-dev'],
                env: $fixture['environment'],
            );

            expect($process->run())
                ->toBe(0)
                ->and(file("{$fixture['root']}/git-commands", FILE_IGNORE_NEW_LINES))
                ->toContain(
                    '-C '.$fixture['checkout'].' cat-file -e '.str_repeat('b', 40).'^{commit}',
                    '-C '.$fixture['checkout'].' fetch --quiet origin '.str_repeat('b', 40),
                    '-C '.$fixture['checkout'].' rev-parse HEAD',
                )
                ->and(file_exists("{$fixture['root']}/fetched"))
                ->toBeTrue();
        } finally {
            new Illuminate\Filesystem\Filesystem()->deleteDirectory($fixture['root']);
        }
    });

    it('hydrates sample Composer dependencies and records the exact lock state', function (
        string $marker,
        bool $autoloaded,
    ) {
        $fixture = sample_hydration_fixture();
        try {
            if ($autoloaded) {
                file_put_contents("{$fixture['checkout']}/vendor/autoload.php", "autoloaded\n");
            }
            file_put_contents("{$fixture['checkout']}/vendor/.orbit-e2e-composer-lock", $marker);

            $process = new Process(
                ['bash', $fixture['script'], 'hydrate', str_repeat('b', 40), 'app-dev'],
                env: $fixture['environment'],
            );

            expect($process->run())
                ->toBe(0)
                ->and(file("{$fixture['root']}/composer-commands", FILE_IGNORE_NEW_LINES))
                ->toBe([
                    'install --working-dir='.$fixture['checkout'].' --no-interaction --no-progress',
                ])
                ->and(file_get_contents("{$fixture['checkout']}/vendor/.orbit-e2e-composer-lock"))
                ->toBe(hash_file('sha256', "{$fixture['checkout']}/composer.lock"));
        } finally {
            new Illuminate\Filesystem\Filesystem()->deleteDirectory($fixture['root']);
        }
    })->with([
        'changed lock' => ['stale-lock', true],
        'missing installed content' => [hash('sha256', "locked dependencies\n"), false],
    ]);

    it('does not publish a sample hydration marker when Composer fails', function () {
        $fixture = sample_hydration_fixture();
        try {
            file_put_contents("{$fixture['checkout']}/vendor/autoload.php", "old autoload\n");
            file_put_contents("{$fixture['checkout']}/vendor/.orbit-e2e-composer-lock", 'stale-lock');
            $environment = [...$fixture['environment'], 'SAMPLE_COMPOSER_FAILS' => '1'];

            $process = new Process(
                ['bash', $fixture['script'], 'hydrate', str_repeat('b', 40), 'app-dev'],
                env: $environment,
            );

            expect($process->run())
                ->not
                ->toBe(0)
                ->and(file_get_contents("{$fixture['checkout']}/vendor/.orbit-e2e-composer-lock"))
                ->toBe('stale-lock');
        } finally {
            new Illuminate\Filesystem\Filesystem()->deleteDirectory($fixture['root']);
        }
    });

    it('hydrates a stock Laravel checkout without a committed Composer lock', function () {
        $fixture = sample_hydration_fixture();
        try {
            unlink("{$fixture['checkout']}/composer.lock");
            $environment = [...$fixture['environment'], 'SAMPLE_COMPOSER_WRITES_LOCK' => '1'];

            $process = new Process(
                ['bash', $fixture['script'], 'hydrate', str_repeat('b', 40), 'app-dev'],
                env: $environment,
            );

            expect($process->run())
                ->toBe(0)
                ->and(file_get_contents("{$fixture['checkout']}/composer.lock"))
                ->toBe("resolved dependencies\n")
                ->and(file_get_contents("{$fixture['checkout']}/vendor/.orbit-e2e-composer-lock"))
                ->toBe(hash_file('sha256', "{$fixture['checkout']}/composer.lock"));
        } finally {
            new Illuminate\Filesystem\Filesystem()->deleteDirectory($fixture['root']);
        }
    });

    it('prepares the stock SQLite database and migrates every sample checkout', function () {
        $fixture = sample_hydration_fixture();
        try {
            file_put_contents("{$fixture['checkout']}/.env", "APP_KEY=base64:fixture\nDB_CONNECTION=sqlite\n");
            file_put_contents("{$fixture['checkout']}/vendor/autoload.php", "autoloaded\n");
            file_put_contents(
                "{$fixture['checkout']}/vendor/.orbit-e2e-composer-lock",
                hash_file('sha256', "{$fixture['checkout']}/composer.lock"),
            );
            file_put_contents("{$fixture['root']}/bin/php", <<<'BASH'
                #!/usr/bin/env bash
                set -euo pipefail
                printf '%s\n' "$*" >> "$SAMPLE_PHP_COMMANDS"
                [[ "$*" == *' migrate '* ]] && [[ -f "$(dirname "$1")/database/database.sqlite" ]]
                exit 0
                BASH);
            chmod("{$fixture['root']}/bin/php", 0o700);
            $environment = [...$fixture['environment'], 'SAMPLE_PHP_COMMANDS' => "{$fixture['root']}/php-commands"];

            $process = new Process(
                ['bash', $fixture['script'], 'hydrate', str_repeat('b', 40), 'app-dev'],
                env: $environment,
            );

            expect($process->run())
                ->toBe(0, $process->getErrorOutput())
                ->and(file_exists("{$fixture['checkout']}/database/database.sqlite"))
                ->toBeTrue()
                ->and(file("{$fixture['root']}/php-commands", FILE_IGNORE_NEW_LINES))
                ->toBe([$fixture['checkout'].'/artisan migrate --force --no-interaction']);
        } finally {
            new Illuminate\Filesystem\Filesystem()->deleteDirectory($fixture['root']);
        }
    });

    it('does not publish a sample hydration marker without installed Composer content', function () {
        $fixture = sample_hydration_fixture();
        try {
            file_put_contents("{$fixture['checkout']}/vendor/.orbit-e2e-composer-lock", 'stale-lock');
            $environment = [...$fixture['environment'], 'SAMPLE_COMPOSER_WRITES_AUTOLOAD' => '0'];

            $process = new Process(
                ['bash', $fixture['script'], 'hydrate', str_repeat('b', 40), 'app-dev'],
                env: $environment,
            );

            expect($process->run())
                ->not
                ->toBe(0)
                ->and(file_get_contents("{$fixture['checkout']}/vendor/.orbit-e2e-composer-lock"))
                ->toBe('stale-lock');
        } finally {
            new Illuminate\Filesystem\Filesystem()->deleteDirectory($fixture['root']);
        }
    });

    it('rejects sample hydration when repository identity or checkout evidence differs', function (
        string $variable,
        string $value,
    ) {
        $fixture = sample_hydration_fixture();
        try {
            $environment = [...$fixture['environment'], $variable => $value];
            $process = new Process(
                ['bash', $fixture['script'], 'hydrate', str_repeat('b', 40), 'app-dev'],
                env: $environment,
            );

            expect($process->run())
                ->not
                ->toBe(0)
                ->and(file_exists("{$fixture['root']}/composer-commands"))
                ->toBeFalse();
        } finally {
            new Illuminate\Filesystem\Filesystem()->deleteDirectory($fixture['root']);
        }
    })->with([
        'wrong remote' => ['SAMPLE_REMOTE_URL', 'https://example.invalid/laravel.git'],
        'wrong head' => ['SAMPLE_HEAD_SHA', str_repeat('c', 40)],
    ]);

    it('does not create duplicate sample resources on a second run', function () {
        $root = temporaryPath('orbit-task7-resources-', 6);
        mkdir($root, 0o700, true);
        $source = file_get_contents(dirname(__DIR__, 3).'/resources/guest/converge-sample-app.sh');
        $script = str_replace('orbit=/home/orbit/orbit/apps/cli/orbit', "orbit={$root}/orbit", $source);
        file_put_contents("{$root}/converge.sh", $script);
        file_put_contents("{$root}/orbit", <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            state=$(dirname "$0")
            printf '%s\n' "$*" >>"$state/commands"
            case "$1" in
              node:list) printf '{"nodes":[{"id":2,"name":"app-dev"},{"id":3,"name":"app-prod"}]}' ;;
              app:list)
                if [[ -s "$state/app" ]]; then printf '{"apps":[{"id":1,"slug":"laravel","name":"Laravel","repository_url":"https://example.invalid/wrong.git"}]}'
                elif [[ -e "$state/app" ]]; then printf '{"apps":[{"id":1,"slug":"laravel","name":"Laravel","repository_url":"https://github.com/laravel/laravel.git"}]}'
                else printf '{"apps":[]}'
                fi
                ;;
              app:new) touch "$state/app"; printf '{"id":1}' ;;
              instance:list)
                printf '{"instances":['
                sep=
                if [[ -e "$state/dev" ]]; then printf '%s{"id":4,"app_id":1,"node_id":2,"name":"e2e-dev","environment":"development","hostname":"laravel.beast"}' "$sep"; sep=,; fi
                if [[ -e "$state/prod" ]]; then printf '%s{"id":5,"app_id":1,"node_id":3,"name":"e2e-prod","environment":"production","hostname":"laravel.internal"}' "$sep"; fi
                printf ']}'
                ;;
              instance:new) [[ "$4" == e2e-dev ]] && touch "$state/dev" || touch "$state/prod"; printf '{"id":4}' ;;
              workspace:list) [[ -e "$state/workspace" ]] && printf '{"workspaces":[{"id":6,"instance_id":4,"name":"e2e","branch":"e2e"}]}' || printf '{"workspaces":[]}' ;;
              workspace:new) touch "$state/workspace"; printf '{"id":6}' ;;
              *) exit 70 ;;
            esac
            BASH);
        chmod("{$root}/orbit", 0o700);

        $arguments = [
            'bash',
            "{$root}/converge.sh",
            'create-resources',
            'app-dev',
            'app-prod',
            str_repeat('a', 40),
        ];
        expect(new Process($arguments)->run())->toBe(0);
        expect(new Process($arguments)->run())->toBe(0);
        $commands = file("{$root}/commands", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        expect(array_filter($commands, fn (string $command): bool => str_starts_with($command, 'app:new ')))
            ->toHaveCount(1)
            ->and(array_filter($commands, fn (string $command): bool => str_starts_with($command, 'instance:new ')))
            ->toHaveCount(2)
            ->and(array_filter($commands, fn (string $command): bool => str_starts_with($command, 'workspace:new ')))
            ->toHaveCount(1);

        file_put_contents("{$root}/app", 'wrong');
        expect(new Process($arguments)->run())->not->toBe(0);
        $commands = file("{$root}/commands", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        expect(array_filter($commands, fn (string $command): bool => str_starts_with($command, 'app:new ')))
            ->toHaveCount(1);
    });

    it('re-projects app roles first and every instance with development last', function () {
        $root = temporaryPath('orbit-task7-reproject-', 6);
        mkdir($root, 0o700, true);
        $source = file_get_contents(dirname(__DIR__, 3).'/resources/guest/converge-sample-app.sh');
        file_put_contents(
            "{$root}/converge.sh",
            str_replace('orbit=/home/orbit/orbit/apps/cli/orbit', "orbit={$root}/orbit", $source),
        );
        file_put_contents("{$root}/orbit", <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            state=$(dirname "$0")
            printf '%s\n' "$*" >>"$state/commands"
            case "$1" in
              node:list) printf '{"nodes":[{"id":1,"name":"gateway","roles":["gateway","vpn"]},{"id":2,"name":"app-dev","roles":["app-dev"]},{"id":3,"name":"app-prod","roles":["app-prod"]}]}' ;;
              node:role:add) [[ "$4" == --converge && "$5" == --json ]]; printf '{"node_id":%s,"node_name":"n","role":"%s","assignment":{"id":9,"role":"%s","status":"active"}}' "$2" "$3" "$3" ;;
              instance:list) printf '{"instances":[{"id":1,"name":"e2e-dev","node_id":2,"environment":"development","php_version":"8.5"},{"id":2,"name":"e2e-prod","node_id":3,"environment":"production","php_version":"8.4"}]}' ;;
              instance:php) status=active; [[ -e "$state/fail-$2" ]] && status=failed; printf '{"id":%s,"name":"i","node_id":0,"status":"%s"}' "$2" "$status" ;;
              *) exit 70 ;;
            esac
            BASH);
        chmod("{$root}/orbit", 0o700);

        expect(new Process(['bash', "{$root}/converge.sh", 'reproject'])->run())->toBe(0);
        expect(file("{$root}/commands", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))->toBe([
            'node:list --json',
            'node:role:add 2 app-dev --converge --json',
            'node:role:add 3 app-prod --converge --json',
            'instance:list --json',
            'instance:php 2 8.4 --json',
            'instance:php 1 8.5 --json',
        ]);

        touch("{$root}/fail-2");
        $failed = new Process(['bash', "{$root}/converge.sh", 'reproject']);
        expect($failed->run())
            ->not
            ->toBe(0)
            ->and($failed->getErrorOutput())
            ->toContain('instance is not active after re-projection')
            ->and(new Process(['bash', "{$root}/converge.sh", 'reproject', 'extra'])->run())
            ->toBe(64);
    });

    it('installs the local_certs fragment inside the managed Caddy version and keeps the product symlink', function () {
        $root = temporaryPath('orbit-task7-internal-tls-', 6);
        $fixture = internal_tls_fixture($root);
        $version = "{$root}/etc/caddy/orbit-versions/1234567890abcdef";
        symlink("{$version}/Caddyfile", "{$root}/etc/caddy/Caddyfile");

        new Process(['bash', "{$root}/converge.sh", 'internal-tls'], env: $fixture['environment'])->mustRun();
        $fragment = "{$version}/fragments/00-orbit-e2e-global.caddy";
        expect(file_get_contents($fragment))
            ->toBe("{\n    local_certs\n}\n")
            ->and(fileperms($fragment) & 0o777)
            ->toBe(0o640)
            ->and(readlink("{$root}/etc/caddy/Caddyfile"))
            ->toBe("{$version}/Caddyfile")
            ->and(file("{$root}/validations", FILE_IGNORE_NEW_LINES))
            ->toBe(["validate --config {$root}/etc/caddy/Caddyfile --adapter caddyfile"])
            ->and(file("{$root}/reloads", FILE_IGNORE_NEW_LINES))
            ->toBe(['reload caddy']);

        // A repeat run validates again but does not reload a settled node.
        new Process(['bash', "{$root}/converge.sh", 'internal-tls'], env: $fixture['environment'])->mustRun();
        expect(file("{$root}/validations", FILE_IGNORE_NEW_LINES))
            ->toHaveCount(2)
            ->and(file("{$root}/reloads", FILE_IGNORE_NEW_LINES))
            ->toHaveCount(1)
            ->and(
                new Process([
                    'bash',
                    "{$root}/converge.sh",
                    'internal-tls',
                    'extra',
                ], env: $fixture['environment'])->run(),
            )
            ->toBe(64);

        // A managed Caddyfile outside the product layout is refused.
        unlink("{$root}/etc/caddy/Caddyfile");
        file_put_contents("{$root}/etc/caddy/Caddyfile", "laravel.internal {\n}\n");
        $process = new Process(['bash', "{$root}/converge.sh", 'internal-tls'], env: $fixture['environment']);
        expect($process->run())->toBe(65)->and($process->getErrorOutput())->toContain('unexpected Caddyfile target');
    });

    it('retires a live e2e wrapper Caddyfile from an older snapshot', function () {
        $root = temporaryPath('orbit-task7-internal-tls-legacy-', 6);
        $fixture = internal_tls_fixture($root);
        $version = "{$root}/etc/caddy/orbit-versions/1234567890abcdef";
        file_put_contents(
            "{$root}/etc/caddy/Caddyfile.orbit-e2e",
            "import {$root}/etc/caddy/orbit-e2e-global.caddy\nimport {$version}/Caddyfile\n",
        );
        symlink('Caddyfile.orbit-e2e', "{$root}/etc/caddy/Caddyfile");
        file_put_contents(
            "{$root}/state/caddy-rendered-path",
            "/etc/caddy/orbit-versions/1234567890abcdef/Caddyfile\n",
        );
        file_put_contents("{$root}/state/caddy-config-sha256", "abc\n");

        new Process(['bash', "{$root}/converge.sh", 'internal-tls'], env: $fixture['environment'])->mustRun();
        expect(readlink("{$root}/etc/caddy/Caddyfile"))
            ->toBe("{$version}/Caddyfile")
            ->and(file_get_contents("{$version}/fragments/00-orbit-e2e-global.caddy"))
            ->toBe("{\n    local_certs\n}\n")
            ->and(file_exists("{$root}/etc/caddy/Caddyfile.orbit-e2e"))
            ->toBeFalse()
            ->and(file_exists("{$root}/state/caddy-rendered-path"))
            ->toBeFalse()
            ->and(file_exists("{$root}/state/caddy-config-sha256"))
            ->toBeFalse()
            ->and(file("{$root}/reloads", FILE_IGNORE_NEW_LINES))
            ->toBe(['reload caddy']);
    });

    it('probes the production site over the internal CA after hydration without touching Caddy', function () {
        $root = temporaryPath('orbit-task7-caddy-', 6);
        mkdir("{$root}/etc/caddy", 0o700, true);
        mkdir("{$root}/state", 0o700, true);
        mkdir("{$root}/bin", 0o700, true);
        mkdir("{$root}/prod/.git", 0o700, true);
        mkdir("{$root}/prod/storage", 0o700, true);
        mkdir("{$root}/prod/bootstrap/cache", 0o700, true);
        mkdir("{$root}/prod/vendor", 0o700, true);
        file_put_contents("{$root}/prod/.env", "APP_KEY=base64:key\n");
        file_put_contents("{$root}/prod/artisan", '');
        file_put_contents("{$root}/prod/composer.lock", "locked dependencies\n");
        file_put_contents("{$root}/prod/vendor/autoload.php", "autoloaded\n");
        file_put_contents(
            "{$root}/prod/vendor/.orbit-e2e-composer-lock",
            hash_file('sha256', "{$root}/prod/composer.lock"),
        );
        file_put_contents("{$root}/etc/caddy/rendered.caddy", "laravel.internal { respond ok }\n");
        symlink('rendered.caddy', "{$root}/etc/caddy/Caddyfile");
        file_put_contents("{$root}/state/caddy-ca-path", "{$root}/ca.crt\n");
        file_put_contents("{$root}/ca.crt", 'ca');

        file_put_contents("{$root}/bin/composer", "#!/usr/bin/env bash\nexit 0\n");
        chmod("{$root}/bin/composer", 0o700);
        file_put_contents(
            "{$root}/bin/curl",
            "#!/usr/bin/env bash\nset -euo pipefail\narguments=\" \$* \"\nprintf '%s\\n' \"\$*\" >>'{$root}/probes'\n"
            ."[[ \"\$arguments\" == *' --retry 10 --retry-delay 2 --retry-connrefused --retry-all-errors --connect-timeout 10 --max-time 30 '* ]]\n"
            ."[[ \"\$arguments\" == *\" --cacert {$root}/ca.crt --resolve laravel.internal:443:127.0.0.1 https://laravel.internal/ \"* ]]\n",
        );
        chmod("{$root}/bin/curl", 0o700);
        foreach (['systemctl', 'caddy'] as $forbidden) {
            file_put_contents(
                "{$root}/bin/{$forbidden}",
                "#!/usr/bin/env bash\nprintf '%s\\n' \"\$*\" >>'{$root}/{$forbidden}-calls'\nexit 1\n",
            );
            chmod("{$root}/bin/{$forbidden}", 0o700);
        }
        file_put_contents(
            "{$root}/bin/git",
            "#!/usr/bin/env bash\n[[ \"\$*\" == *'remote get-url origin'* ]] && printf '%s\\n' https://github.com/laravel/laravel.git\n[[ \"\$*\" == *'rev-parse HEAD'* ]] && printf '%s\\n' "
            .str_repeat('b', 40)
            ."\nexit 0\n",
        );
        chmod("{$root}/bin/git", 0o700);

        $source = file_get_contents(dirname(__DIR__, 3).'/resources/guest/converge-sample-app.sh');
        file_put_contents("{$root}/converge.sh", str_replace(
            ['/var/www/laravel/e2e-prod', '/etc/caddy', '/var/lib/orbit-e2e'],
            ["{$root}/prod", "{$root}/etc/caddy", "{$root}/state"],
            $source,
        ));
        $environment = ['PATH' => "{$root}/bin:".getenv('PATH')];
        $arguments = ['bash', "{$root}/converge.sh", 'hydrate', str_repeat('b', 40), 'app-prod'];
        new Process($arguments, env: $environment)->mustRun();
        new Process($arguments, env: $environment)->mustRun();

        expect(file("{$root}/probes", FILE_IGNORE_NEW_LINES))
            ->toHaveCount(2)
            ->and(readlink("{$root}/etc/caddy/Caddyfile"))
            ->toBe('rendered.caddy')
            ->and(file_get_contents("{$root}/etc/caddy/rendered.caddy"))
            ->toBe("laravel.internal { respond ok }\n")
            ->and(file_exists("{$root}/systemctl-calls"))
            ->toBeFalse()
            ->and(file_exists("{$root}/caddy-calls"))
            ->toBeFalse()
            ->and(glob("{$root}/etc/caddy/Caddyfile.orbit-e2e*"))
            ->toBe([]);
    });
    it('proves a mounted source through the mountpoint and the git pointer hash', function () {
        $root = temporaryPath('orbit-verifier-mounted-', 4);
        mkdir("{$root}/bin", 0o700, true);
        mkdir("{$root}/source/apps/gateway", 0o700, true);
        $sha = str_repeat('a', 40);
        $tree = str_repeat('b', 64);
        $marker = "{$root}/source-state";
        file_put_contents("{$root}/source/.git", "gitdir: /srv/orbit/.git/worktrees/feature\n");
        file_put_contents("{$root}/source/apps/gateway/artisan", "#!/usr/bin/env php\n");
        $pointer = hash('sha256', (string) file_get_contents("{$root}/source/.git"));
        file_put_contents(
            "{$root}/bin/git",
            "#!/usr/bin/env bash\nprintf 'guest-git-ran\\n' >> '{$root}/git-ran'\nexit 1\n",
        );
        // The fake mountpoint answers for the source root only while the flag file exists.
        file_put_contents(
            "{$root}/bin/mountpoint",
            "#!/usr/bin/env bash\n[[ \"\$3\" == '{$root}/source' && -e '{$root}/mounted' ]]\n",
        );
        chmod("{$root}/bin/git", 0o700);
        chmod("{$root}/bin/mountpoint", 0o700);
        touch("{$root}/mounted");
        $script = str_replace(
            ['source_marker=/var/lib/orbit-e2e/source-state', 'source_root=/home/orbit/orbit'],
            ["source_marker={$marker}", "source_root={$root}/source"],
            file_get_contents(dirname(__DIR__, 3).'/resources/guest/verify-topology.sh'),
        );
        file_put_contents("{$root}/verify.sh", $script);
        chmod("{$root}/verify.sh", 0o700);
        $environment = ['PATH' => "{$root}/bin:".getenv('PATH')];
        $run = static fn (array $arguments): Process => new Process(
            ['bash', "{$root}/verify.sh", ...$arguments],
            env: $environment,
        );
        $writeMarker = static fn (array $overrides = []): int|false => file_put_contents(
            $marker,
            json_encode(
                $overrides + ['sha' => $sha, 'tree' => $tree, 'mounted' => true, 'git_pointer_sha256' => $pointer],
            )
                ."\n",
        );
        $gateway = ['source.gateway', 'readiness', $sha, 'orbit-e2e-x-gateway', $pointer];
        $manifest = ['source.manifest', 'readiness', $sha, 'orbit-e2e-x-gateway', $tree, '', $pointer];

        try {
            $writeMarker();
            $evidence = json_decode($run($gateway)->mustRun()->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            expect($evidence)->toMatchArray([
                'probe' => 'source.gateway',
                'expected' => "{$sha}:git-pointer={$pointer}",
                'observed' => "{$sha}:git-pointer={$pointer}",
            ]);

            $manifestEvidence = json_decode($run($manifest)->mustRun()->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            expect($manifestEvidence)->toMatchArray([
                'expected' => "{$sha}:{$tree}:git-pointer={$pointer}",
                'observed' => "{$sha}:{$tree}:git-pointer={$pointer}",
            ]);

            $clean = json_decode(
                $run(['source.manifest', 'readiness', $sha, 'orbit-e2e-x-gateway', '-', '', $pointer])
                    ->mustRun()
                    ->getOutput(),
                true,
                16,
                JSON_THROW_ON_ERROR,
            );
            expect($clean)->toMatchArray(['expected' => "{$sha}:{$tree}:git-pointer={$pointer}"]);

            // Host-side mismatches: identity, tree, and the expected pointer hash.
            expect($run(['source.gateway', 'readiness', str_repeat('c', 40), 'orbit-e2e-x-gateway', $pointer])->run())
                ->not->toBe(0)->and(
                    $run([
                        'source.manifest',
                        'readiness',
                        $sha,
                        'orbit-e2e-x-gateway',
                        str_repeat('d', 64),
                        '',
                        $pointer,
                    ])->run(),
                )
                ->not->toBe(0)->and(
                    $run(['source.gateway', 'readiness', $sha, 'orbit-e2e-x-gateway', str_repeat('e', 64)])->run(),
                )
                ->not->toBe(0)->and(
                    $run(['source.gateway', 'readiness', $sha, 'orbit-e2e-x-gateway', 'not-a-hash'])->run(),
                )
                ->not->toBe(0);

            // A marker without the mount, or with another pointer hash, proves nothing.
            unlink("{$root}/mounted");
            expect($run($gateway)->run())->not->toBe(0)->and($run($manifest)->run())->not->toBe(0);
            touch("{$root}/mounted");
            $writeMarker(['git_pointer_sha256' => str_repeat('e', 64)]);
            expect($run($gateway)->run())->not->toBe(0);

            // The pointer file the guest sees must hash to the expected value.
            $writeMarker();
            file_put_contents("{$root}/source/.git", "gitdir: /srv/other/.git/worktrees/feature\n");
            expect($run($gateway)->run())->not->toBe(0)->and($run($manifest)->run())->not->toBe(0);
            file_put_contents("{$root}/source/.git", "gitdir: /srv/orbit/.git/worktrees/feature\n");
            unlink("{$root}/source/apps/gateway/artisan");
            expect($run($gateway)->run())->not->toBe(0);
            file_put_contents("{$root}/source/apps/gateway/artisan", "#!/usr/bin/env php\n");

            // The host contract is explicit: no marker for a mounted probe, and no
            // mounted marker for a transferred checkout.
            $writeMarker(['mounted' => false]);
            expect($run($gateway)->run())->not->toBe(0);
            $writeMarker();
            expect($run(['source.gateway', 'readiness', $sha, 'orbit-e2e-x-gateway'])->run())->not->toBe(0);
            unlink($marker);
            expect($run($gateway)->run())->not->toBe(0);

            $writeMarker();
            file_put_contents($marker, "{malformed\n");
            expect($run($manifest)->run())
                ->not
                ->toBe(0)
                ->and(file_exists("{$root}/git-ran"))
                ->toBeFalse();
        } finally {
            new Illuminate\Filesystem\Filesystem()->deleteDirectory($root);
        }
    });
});
