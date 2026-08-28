<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

describe('convergence guest scripts', function () {
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
        $production = file_get_contents("{$guest}/converge-app-prod-internal-tls.sh");
        $sample = file_get_contents("{$guest}/converge-sample-app.sh");

        expect($prepare)
            ->toContain('/home/orbit/orbit/apps/e2e/resources/guest/hydrate-orbit.sh')
            ->and($gateway)
            ->toContain('/home/orbit/orbit', 'database.sqlite', 'migrate --force', 'orbit:bootstrap')
            ->and($production)
            ->toContain('local_certs', 'caddy validate', 'systemctl reload caddy', 'caddy-ca-path')
            ->and($sample)
            ->toContain(
                'https://github.com/laravel/laravel.git',
                'app:new laravel',
                'instance:new',
                'workspace:new',
                'APP_KEY=base64:',
                'composer install',
                'readlink -f /etc/caddy/Caddyfile',
                'curl --fail --silent --show-error --cacert',
            );
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
