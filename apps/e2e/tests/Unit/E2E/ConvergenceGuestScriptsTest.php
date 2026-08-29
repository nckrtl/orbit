<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

function gateway_prerequisite_fixture(): string
{
    $root = sys_get_temp_dir().'/orbit-gateway-prereqs-'.bin2hex(random_bytes(4));
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
    $root = sys_get_temp_dir().'/orbit-sample-hydration-'.bin2hex(random_bytes(4));
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

/** @return array{root:string,script:string,state:string,commands:string,caddy:string,ca:string} */
function convergence_app_fixture(string $scriptName): array
{
    $root = sys_get_temp_dir().'/orbit-'.$scriptName.'-'.bin2hex(random_bytes(4));
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
        case ",${ORBIT_PHP_FAIL:-}," in
          *",retarget,"*) [[ "$*" == *' orbit:node-retarget '* ]] && exit 1 ;;
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

describe('convergence guest scripts', function () {
    it('runs Gateway Artisan commands from the Gateway checkout', function () {
        $root = sys_get_temp_dir().'/orbit-gateway-converge-'.bin2hex(random_bytes(4));
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

    it('reprovisions app-dev when the prepared marker changes and rewrites both markers only after success', function (): void {
        $fixture = convergence_app_fixture('converge-app-dev.sh');
        file_put_contents($fixture['state'].'/node-provision-app-dev', str_repeat('a', 64));
        file_put_contents($fixture['state'].'/node-provision-app-dev.address', str_repeat('b', 64));

        try {
            $process = new Process(['bash', $fixture['script'], 'app-dev', '192.0.2.11', 'x86_64'], env: [
                'PATH' => "{$fixture['root']}/bin:".getenv('PATH'),
            ]);

            expect($process->run())
                ->toBe(0)
                ->and(file($fixture['commands'], FILE_IGNORE_NEW_LINES))
                ->toHaveCount(1)
                ->and(file_get_contents($fixture['state'].'/node-provision-app-dev'))
                ->toMatch('/\A[0-9a-f]{64}\n\z/')
                ->and(file_get_contents($fixture['state'].'/node-provision-app-dev.address'))
                ->toMatch('/\A[0-9a-f]{64}\n\z/');
            expect(file_get_contents($fixture['commands']))
                ->toContain('orbit:node-provision')
                ->not->toContain('orbit:node-retarget');
        } finally {
            new Illuminate\Filesystem\Filesystem()->deleteDirectory($fixture['root']);
        }
    });

    it('retargets app-dev only when the prepared marker matches and the address marker changes', function (): void {
        $fixture = convergence_app_fixture('converge-app-dev.sh');

        try {
            $first = new Process(['bash', $fixture['script'], 'app-dev', '192.0.2.11', 'x86_64'], env: [
                'PATH' => "{$fixture['root']}/bin:".getenv('PATH'),
            ]);
            expect($first->run())->toBe(0);
            unlink($fixture['commands']);

            $second = new Process(['bash', $fixture['script'], 'app-dev', '192.0.2.12', 'x86_64'], env: [
                'PATH' => "{$fixture['root']}/bin:".getenv('PATH'),
            ]);

            expect($second->run())
                ->toBe(0)
                ->and(file_get_contents($fixture['commands']))
                ->toContain('orbit:node-retarget')
                ->not->toContain('orbit:node-provision');
        } finally {
            new Illuminate\Filesystem\Filesystem()->deleteDirectory($fixture['root']);
        }
    });

    it('fails closed when a prepared marker is malformed', function (): void {
        $fixture = convergence_app_fixture('converge-app-dev.sh');
        file_put_contents($fixture['state'].'/node-provision-app-dev', "bad-marker\n");

        try {
            $process = new Process(['bash', $fixture['script'], 'app-dev', '192.0.2.11', 'x86_64'], env: [
                'PATH' => "{$fixture['root']}/bin:".getenv('PATH'),
            ]);

            expect($process->run())
                ->not
                ->toBe(0)
                ->and(file_exists($fixture['commands']))
                ->toBeFalse();
        } finally {
            new Illuminate\Filesystem\Filesystem()->deleteDirectory($fixture['root']);
        }
    });

    it('does not update app-dev markers when provisioning fails', function (): void {
        $fixture = convergence_app_fixture('converge-app-dev.sh');
        file_put_contents($fixture['state'].'/node-provision-app-dev', str_repeat('a', 64));
        file_put_contents($fixture['state'].'/node-provision-app-dev.address', str_repeat('b', 64));

        try {
            $process = new Process(['bash', $fixture['script'], 'app-dev', '192.0.2.11', 'x86_64'], env: [
                'PATH' => "{$fixture['root']}/bin:".getenv('PATH'),
                'ORBIT_PHP_FAIL' => 'provision',
            ]);

            expect($process->run())
                ->not
                ->toBe(0)
                ->and(file_get_contents($fixture['state'].'/node-provision-app-dev'))
                ->toBe(str_repeat('a', 64))
                ->and(file_get_contents($fixture['state'].'/node-provision-app-dev.address'))
                ->toBe(str_repeat('b', 64));
        } finally {
            new Illuminate\Filesystem\Filesystem()->deleteDirectory($fixture['root']);
        }
    });

    it('invalidates both app-dev markers when retarget fails so the next run reprovisions', function (): void {
        $fixture = convergence_app_fixture('converge-app-dev.sh');

        try {
            $first = new Process(['bash', $fixture['script'], 'app-dev', '192.0.2.11', 'x86_64'], env: [
                'PATH' => "{$fixture['root']}/bin:".getenv('PATH'),
            ]);
            expect($first->run())->toBe(0);
            unlink($fixture['commands']);

            $failure = new Process(['bash', $fixture['script'], 'app-dev', '192.0.2.12', 'x86_64'], env: [
                'PATH' => "{$fixture['root']}/bin:".getenv('PATH'),
                'ORBIT_PHP_FAIL' => 'retarget',
            ]);
            expect($failure->run())
                ->not
                ->toBe(0)
                ->and(file_exists($fixture['state'].'/node-provision-app-dev'))
                ->toBeFalse()
                ->and(file_exists($fixture['state'].'/node-provision-app-dev.address'))
                ->toBeFalse();

            unlink($fixture['commands']);
            $recovery = new Process(['bash', $fixture['script'], 'app-dev', '192.0.2.12', 'x86_64'], env: [
                'PATH' => "{$fixture['root']}/bin:".getenv('PATH'),
            ]);

            expect($recovery->run())
                ->toBe(0)
                ->and(file_get_contents($fixture['commands']))
                ->toContain('orbit:node-provision')
                ->not->toContain('orbit:node-retarget');
        } finally {
            new Illuminate\Filesystem\Filesystem()->deleteDirectory($fixture['root']);
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

    it('defers the app-prod Caddy wrapper until the sample instance exists', function (): void {
        $guest = dirname(__DIR__, 3).'/resources/guest';
        $production = file_get_contents("{$guest}/converge-app-prod-internal-tls.sh");
        $sample = file_get_contents("{$guest}/converge-sample-app.sh");

        expect($production)
            ->toContain('orbit-e2e-global.caddy', 'local_certs', 'caddy-ca-path')
            ->not->toContain('Caddyfile.orbit-e2e')->and($production)
            ->not->toContain('readlink -f')->and($production)
            ->not->toContain('systemctl reload caddy')->and($sample)->toContain(
                'Caddyfile.orbit-e2e',
                'readlink -f /etc/caddy/Caddyfile',
                'systemctl reload caddy',
            );
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
    });

    it('returns complete structured evidence for a command-only VM probe', function () {
        $root = sys_get_temp_dir().'/orbit-verifier-'.bin2hex(random_bytes(4));
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
        $root = sys_get_temp_dir().'/orbit-verifier-degraded-'.bin2hex(random_bytes(4));
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
        $root = sys_get_temp_dir().'/orbit-verifier-source-'.bin2hex(random_bytes(4));
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
        $root = sys_get_temp_dir().'/orbit-verifier-state-'.bin2hex(random_bytes(4));
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
        $root = sys_get_temp_dir().'/orbit-verifier-fail-'.bin2hex(random_bytes(4));
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

    it('fails wireguard reachability before SSH when the app-prod route is missing', function () {
        $root = sys_get_temp_dir().'/orbit-verifier-wireguard-'.bin2hex(random_bytes(4));
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

        $source = file_get_contents(dirname(__DIR__, 3).'/resources/guest/verify-topology.sh');
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
            $source,
        );
        file_put_contents("{$root}/verify-topology.sh", $script);
        chmod("{$root}/verify-topology.sh", 0o700);

        $process = new Process([
            'bash',
            "{$root}/verify-topology.sh",
            'wireguard.reachability',
            'readiness',
            str_repeat('a', 40),
            'orbit-e2e-standby-gateway',
            'app-dev',
            'app-prod',
        ], env: ['PATH' => "{$root}/bin:".getenv('PATH')]);

        expect($process->run())
            ->not
            ->toBe(0)
            ->and(trim($process->getOutput()))
            ->toBe('')
            ->and(file_exists("{$root}/ssh-reached"))
            ->toBeFalse();
    });

    it('proves the exact control-plane role assignments', function () {
        $root = sys_get_temp_dir().'/orbit-verifier-roles-'.bin2hex(random_bytes(4));
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

            $pdo->exec("UPDATE node_roles SET status = 'active' WHERE role = 'app-dev'");
            $pdo->exec("INSERT INTO node_roles VALUES (2, 'gateway', 'active')");
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
            'repo=/home/orbit/orbit',
            'orbit-overlay.paths',
            'orbit-source-state',
            'gateway:status',
            'gateway.orbit',
            'caddy-ca-path',
            'SELECT wireguard_address FROM nodes',
            '"orbit@$app_dev_address"',
            'StrictHostKeyChecking=yes',
            'known_hosts=/home/orbit/.orbit/ssh/known_hosts',
            'UserKnownHostsFile="$known_hosts"',
        );
        expect($source)
            ->not->toContain('HostKeyAlias=')
            ->not->toContain('sqlite3')
            ->not->toContain('StrictHostKeyChecking=no')->toContain(
                'awk -v expected="$app_dev_address/32"',
                'awk -v expected="$app_prod_address/32"',
            );
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
                'readlink -f /etc/caddy/Caddyfile',
                'curl --fail --silent --show-error --cacert',
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
                'ssh-keyscan -t ed25519 -- "$2"',
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
                'ssh-keyscan -t ed25519 -- "$2"',
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
        $root = sys_get_temp_dir().'/orbit-task7-resources-'.bin2hex(random_bytes(6));
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

    it('reuses the original rendered Caddy config without recursive imports', function () {
        $root = sys_get_temp_dir().'/orbit-task7-caddy-'.bin2hex(random_bytes(6));
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
        file_put_contents("{$root}/etc/caddy/orbit-e2e-global.caddy", "{\n local_certs\n}\n");
        symlink('rendered.caddy', "{$root}/etc/caddy/Caddyfile");
        file_put_contents("{$root}/state/caddy-ca-path", "{$root}/ca.crt\n");
        file_put_contents("{$root}/ca.crt", 'ca');

        foreach (['composer', 'curl'] as $command) {
            file_put_contents("{$root}/bin/{$command}", "#!/usr/bin/env bash\nexit 0\n");
            chmod("{$root}/bin/{$command}", 0o700);
        }
        file_put_contents("{$root}/bin/systemctl", "#!/usr/bin/env bash\nprintf '%s\\n' \"\$*\" >>'{$root}/reloads'\n");
        chmod("{$root}/bin/systemctl", 0o700);
        file_put_contents(
            "{$root}/bin/git",
            "#!/usr/bin/env bash\n[[ \"\$*\" == *'remote get-url origin'* ]] && printf '%s\\n' https://github.com/laravel/laravel.git\n[[ \"\$*\" == *'rev-parse HEAD'* ]] && printf '%s\\n' "
            .str_repeat('b', 40)
            ."\nexit 0\n",
        );
        chmod("{$root}/bin/git", 0o700);
        file_put_contents("{$root}/bin/caddy", <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            printf '%s\n' "$*" >>__VALIDATIONS__
            config=
            while [[ $# -gt 0 ]]; do [[ "$1" == --config ]] && config=$2 && shift; shift; done
            [[ -f "$config" ]]
            [[ $(grep -c '^import ' "$config") -le 2 ]]
            grep -q 'rendered.caddy' "$config" || [[ "$config" == *orbit-e2e-global.caddy ]]
            BASH);
        $caddy = str_replace('__VALIDATIONS__', "{$root}/validations", file_get_contents("{$root}/bin/caddy"));
        file_put_contents("{$root}/bin/caddy", $caddy);
        chmod("{$root}/bin/caddy", 0o700);

        $source = file_get_contents(dirname(__DIR__, 3).'/resources/guest/converge-sample-app.sh');
        $script = str_replace(
            ['/var/www/laravel/e2e-prod', '/etc/caddy', '/var/lib/orbit-e2e'],
            ["{$root}/prod", "{$root}/etc/caddy", "{$root}/state"],
            $source,
        );
        file_put_contents("{$root}/converge.sh", $script);
        $environment = ['PATH' => "{$root}/bin:".getenv('PATH')];
        $arguments = ['bash', "{$root}/converge.sh", 'hydrate', str_repeat('b', 40), 'app-prod'];
        new Process($arguments, env: $environment)->mustRun();
        new Process($arguments, env: $environment)->mustRun();

        $wrapper = file_get_contents("{$root}/etc/caddy/Caddyfile.orbit-e2e");
        expect($wrapper)
            ->toContain("import {$root}/etc/caddy/rendered.caddy")
            ->and($wrapper)
            ->not
            ->toContain('import '.$root.'/etc/caddy/Caddyfile.orbit-e2e')
            ->and(file_get_contents("{$root}/etc/caddy/rendered.caddy"))
            ->toBe("laravel.internal { respond ok }\n")
            ->and(file("{$root}/validations", FILE_IGNORE_NEW_LINES))
            ->toHaveCount(2)
            ->and(file("{$root}/reloads", FILE_IGNORE_NEW_LINES))
            ->toHaveCount(1);
    });
});
