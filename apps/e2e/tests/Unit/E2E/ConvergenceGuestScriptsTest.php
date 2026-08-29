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

    it('returns only three JSON keys for a command-only VM probe', function () {
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
        ], env: ['PATH' => "{$root}/bin:".getenv('PATH')]);
        expect($process->mustRun()->getOutput())->toBe(json_encode([
            'probe' => 'vm.gateway.running',
            'passed' => true,
            'identity' => $sha,
        ])
            ."\n");
    });

    it('accepts degraded system state but rejects other non-failed states', function () {
        $root = sys_get_temp_dir().'/orbit-verifier-state-'.bin2hex(random_bytes(4));
        mkdir("{$root}/bin", 0o700, true);
        file_put_contents("{$root}/bin/systemctl", "#!/usr/bin/env bash\nprintf '%s\\n' \"\$SYSTEM_STATE\"\n");
        chmod("{$root}/bin/systemctl", 0o700);
        $script = dirname(__DIR__, 3).'/resources/guest/verify-topology.sh';
        $sha = str_repeat('a', 40);

        $degraded = new Process(['bash', $script, 'vm.gateway.running', 'readiness', $sha], env: [
            'PATH' => "{$root}/bin:".getenv('PATH'),
            'SYSTEM_STATE' => 'degraded',
        ]);
        expect($degraded->run())->toBe(0);

        $starting = new Process(['bash', $script, 'vm.gateway.running', 'readiness', $sha], env: [
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
        ], env: ['PATH' => "{$root}/bin:".getenv('PATH')]);
        expect($process->run())->not->toBe(0)->and(trim($process->getOutput()))->toBe('');
    });

    it('fails wireguard reachability before SSH when the app-prod route is missing', function () {
        $root = sys_get_temp_dir().'/orbit-verifier-wireguard-'.bin2hex(random_bytes(4));
        mkdir("{$root}/bin", 0o700, true);
        mkdir("{$root}/home/orbit/.orbit/ssh", 0o700, true);
        file_put_contents("{$root}/gateway.sqlite", 'fixture');
        file_put_contents("{$root}/home/orbit/.orbit/ssh/id_ed25519", 'fixture');

        file_put_contents("{$root}/bin/sqlite3", <<<'BASH'
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

        foreach (['sqlite3', 'wg', 'sudo', 'ssh'] as $command) {
            chmod("{$root}/bin/{$command}", 0o700);
        }

        $source = file_get_contents(dirname(__DIR__, 3).'/resources/guest/verify-topology.sh');
        $script = str_replace(
            ['/home/orbit/.orbit/gateway.sqlite', '/home/orbit/.orbit/ssh/id_ed25519'],
            ["{$root}/gateway.sqlite", "{$root}/home/orbit/.orbit/ssh/id_ed25519"],
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

    it('keeps verifier contract paths and commands explicit', function () {
        $source = file_get_contents(dirname(__DIR__, 3).'/resources/guest/verify-topology.sh');
        expect($source)->toContain(
            'php8.5-fpm',
            'wg-quick@orbit',
            'wg show orbit',
            'orbit',
            '/home/orbit/apps/laravel',
            '/home/orbit/.orbit/worktrees/laravel/e2e',
            '/var/www/laravel/e2e-prod',
            '/home/orbit/orbit/.git/orbit-overlay.paths',
            'gateway:status',
            'gateway.orbit',
            'caddy-ca-path',
            'sqlite3 -cmd ".parameter set :name $app_dev_name"',
            'HostKeyAlias="$app_dev_name"',
            '"orbit@$app_dev_address"',
            'StrictHostKeyChecking=yes',
        );
        expect($source)
            ->not->toContain('"orbit@$app_dev_name"')
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
                'systemctl reload caddy',
                'caddy-ca-path',
            )->and($sample)->toContain(
                'https://github.com/laravel/laravel.git',
                'app:new laravel',
                'instance:new',
                'workspace:new',
                'APP_KEY=base64:',
                'composer install',
                'runtime_user=orbit',
                'runtime_user=orbit-laravel',
                'sudo -u "$runtime_user"',
                '/home/orbit/.orbit/e2e-gateway-root-ca.pem',
                'readlink -f /etc/caddy/Caddyfile',
                'curl --fail --silent --show-error --cacert',
            )->and($hydrate)->toContain(
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
                '-- orbit@"$1"',
            )
            ->not->toContain('ssh -o BatchMode=yes -- "$1"');
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
              node:show) [[ "$2" == *app-dev ]] && printf '{"id":2}' || printf '{"id":3}' ;;
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
              workspace:list) [[ -e "$state/workspace" ]] && printf '{"workspaces":[{"id":6,"instance_id":4,"name":"e2e","branch":"e2e-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}]}' || printf '{"workspaces":[]}' ;;
              workspace:new) touch "$state/workspace"; printf '{"id":6}' ;;
              *) exit 70 ;;
            esac
            BASH);
        chmod("{$root}/orbit", 0o700);

        $arguments = [
            'bash',
            "{$root}/converge.sh",
            'create-resources',
            'orbit-e2e-nck-123-app-dev',
            'orbit-e2e-nck-123-app-prod',
            str_repeat('a', 40),
        ];
        new Process($arguments)->mustRun();
        new Process($arguments)->mustRun();
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
        file_put_contents("{$root}/prod/.env", "APP_KEY=base64:key\n");
        file_put_contents("{$root}/prod/artisan", '');
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
            ->toHaveCount(2);
    });
});
