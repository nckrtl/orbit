<?php

declare(strict_types=1);

use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\UbuntuRelease;
use App\Infrastructure\Nodes\Roles\NodeRolePrerequisiteCommandFactory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('uses fixed package lists for every role', function (): void {
    expect(class_exists(NodeRolePrerequisiteCommandFactory::class))->toBeTrue();

    $factory = new NodeRolePrerequisiteCommandFactory;
    $account = default_managed_user_account();

    expect(role_prerequisite_packages($factory->make(RoleName::AppDev, $account)))
        ->toBe(['acl', 'attr', 'caddy', 'composer', 'docker.io', 'git', 'openssl', 'unzip'])
        ->and(role_prerequisite_packages($factory->make(RoleName::AppProd, $account)))
        ->toBe(['acl', 'attr', 'caddy', 'composer', 'docker.io', 'git', 'openssl', 'unzip'])
        ->and(role_prerequisite_packages($factory->make(RoleName::Vpn, $account)))
        ->toBe(['dnsmasq', 'openssl'])
        ->and($factory->make(RoleName::Gateway, $account)->arguments)
        ->toBe(['true']);
});

it('uses healthy Docker CE as the private Docker prerequisite without allowing removals', function (): void {
    $script =
        new NodeRolePrerequisiteCommandFactory()->make(RoleName::AppProd, default_managed_user_account())->input ?? '';
    $preflight = Str::before($script, 'install -d -m 0755 /opt/orbit');
    $fixture = role_prerequisite_os_release_fixture("ID=ubuntu\nVERSION_CODENAME=\"resolute\"\n");
    $root = sys_get_temp_dir().'/orbit-docker-ce-'.Str::uuid();
    $filesystem = new Filesystem;
    $filesystem->makeDirectory("{$root}/bin", 0o755, true);
    $filesystem->put("{$root}/bin/apt-get", "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> \"\$APT_LOG\"\n");
    $filesystem->put(
        "{$root}/bin/dpkg-query",
        "#!/bin/sh\npackage=\${3##*=}\n[ \"\$DOCKER_CE\" = healthy ] || [ \"\$DOCKER_CE\" != missing-\"\$package\" ] && printf 'install ok installed\\n'\n",
    );
    $filesystem->put("{$root}/bin/systemctl", "#!/bin/sh\n[ \"\$DOCKER_CE\" = healthy ] && exit 0\nexit 1\n");
    foreach (['apt-get', 'dpkg-query', 'systemctl'] as $binary) {
        chmod("{$root}/bin/{$binary}", 0o755);
    }
    $docker = "{$root}/docker";
    $filesystem->put($docker, "#!/bin/sh\nexit 0\n");
    chmod($docker, 0o755);

    try {
        foreach ([
            'healthy',
            'inactive',
            'missing-docker-ce',
            'missing-docker-ce-cli',
            'missing-containerd.io',
        ] as $state) {
            $log = "{$root}/{$state}.log";
            $process = new Process(role_prerequisite_process_arguments(
                RoleName::AppProd,
                ['acl', 'docker.io', 'git'],
            ));
            $process->setEnv(['PATH' => "{$root}/bin:".getenv('PATH'), 'APT_LOG' => $log, 'DOCKER_CE' => $state]);
            $process->setInput(str_replace(
                ['/etc/os-release', '/usr/bin/docker', 'export DEBIAN_FRONTEND=noninteractive'],
                [$fixture, $docker, 'export DEBIAN_FRONTEND=noninteractive'],
                $preflight,
            ));
            $process->run();

            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
            $commands = file($log, FILE_IGNORE_NEW_LINES);
            expect($commands)
                ->toHaveCount(2)
                ->and($commands[0])
                ->toBe('update')
                ->and($commands[1])
                ->toBe(
                    $state === 'healthy'
                        ? 'install --yes --no-install-recommends --no-remove -- acl git'
                        : 'install --yes --no-install-recommends --no-remove -- acl docker.io git',
                );
            expect(implode(' ', $commands))->not->toContain(' remove ', ' purge ', ' autoremove ');
        }
    } finally {
        $filesystem->deleteDirectory($root);
        if (is_file($fixture)) {
            unlink($fixture);
        }
    }
});

it('validates the supported Ubuntu release before any non-gateway mutation', function (RoleName $role): void {
    $script = new NodeRolePrerequisiteCommandFactory()->make($role, default_managed_user_account())->input ?? '';
    $preflight = Str::before($script, "export DEBIAN_FRONTEND=noninteractive\n");
    $marker = sys_get_temp_dir().'/orbit-role-marker-'.Str::uuid();
    $requirement = UbuntuRelease::requirementTextFor(UbuntuRelease::forRole($role));
    $supportedReleases = array_map(
        static fn (UbuntuRelease $release): string => $release->value,
        UbuntuRelease::forRole($role),
    );
    $unsupportedContents = $role === RoleName::AppDev
        ? "ID=ubuntu\nVERSION_CODENAME=jammy\n"
        : "ID=ubuntu\nVERSION_CODENAME=noble\n";
    $supportedFixture = role_prerequisite_os_release_fixture(
        "ID='ubuntu'\nVERSION_CODENAME='{$supportedReleases[0]}'\n",
    );
    $unsupportedFixture = role_prerequisite_os_release_fixture($unsupportedContents);

    try {
        $supportedProcess = new Process([
            'bash',
            '-seu',
            '--',
            $marker,
            ...role_prerequisite_process_arguments(
                $role,
                [],
                includeShell: false,
                requirement: $requirement,
                releases: $supportedReleases,
            ),
        ]);
        $supportedProcess->setEnv(['PATH' => getenv('PATH') ?: '']);
        $supportedProcess->setInput(
            "marker_path=\$1\nshift\n"
            .str_replace('/etc/os-release', $supportedFixture, $preflight)
            ."printf 'mutation-marker\n' > \"\$marker_path\"\n",
        );
        $supportedProcess->run();

        expect($supportedProcess->isSuccessful())
            ->toBeTrue($supportedProcess->getErrorOutput())
            ->and(trim(file_get_contents($marker)))
            ->toBe('mutation-marker');

        if (is_file($marker)) {
            unlink($marker);
        }

        $unsupportedProcess = new Process([
            'bash',
            '-seu',
            '--',
            $marker,
            ...role_prerequisite_process_arguments(
                $role,
                [],
                includeShell: false,
                requirement: $requirement,
                releases: $supportedReleases,
            ),
        ]);
        $unsupportedProcess->setEnv(['PATH' => getenv('PATH') ?: '']);
        $unsupportedProcess->setInput(
            "marker_path=\$1\nshift\n"
            .str_replace('/etc/os-release', $unsupportedFixture, $preflight)
            ."printf 'mutation-marker\n' > \"\$marker_path\"\n",
        );
        $unsupportedProcess->run();

        expect($unsupportedProcess->isSuccessful())
            ->toBeFalse()
            ->and($unsupportedProcess->getErrorOutput())
            ->toContain($requirement)
            ->and(file_exists($marker))
            ->toBeFalse();
    } finally {
        if (is_file($marker)) {
            unlink($marker);
        }
        if (is_file($supportedFixture)) {
            unlink($supportedFixture);
        }
        if (is_file($unsupportedFixture)) {
            unlink($unsupportedFixture);
        }
    }
})->with([RoleName::AppDev, RoleName::AppProd, RoleName::Vpn]);

it('uses fixed managed account argv and dynamic paths for a nondefault home', function (): void {
    $account = nondefault_managed_user_account();
    $command = new NodeRolePrerequisiteCommandFactory()->make(RoleName::AppDev, $account);

    expect(array_slice($command->arguments, 0, 9))
        ->toBe(['sudo', 'bash', '-seu', '--', 'app-dev', 'nckrtl', 'nckrtl', '/srv/users/nckrtl', '2'])
        ->and($command->input ?? '')
        ->toContain(
            'managed_user=$1',
            'managed_group=$2',
            'managed_home=$3',
            'install -d -m 0755 -o "$managed_user" -g "$managed_group" "$managed_home/apps" "$managed_home/.orbit/worktrees"',
            'setfacl -m u:caddy:--x "$managed_home" "$managed_home/apps" "$managed_home/.orbit" "$managed_home/.orbit/worktrees"',
            'sudo -u "$managed_user" -H env VP_HOME=/opt/orbit/vite-plus',
            'sudo -u "$managed_user" -H env COMPOSER_HOME=/opt/orbit/composer /usr/bin/composer --version --no-ansi',
        )
        ->not->toContain('/home/orbit/apps', '/home/orbit/.orbit/worktrees', 'sudo -u orbit');
});

it('uses the managed account for ownership validation on shared prerequisites', function (): void {
    $script =
        new NodeRolePrerequisiteCommandFactory()->make(
            RoleName::AppDev,
            nondefault_managed_user_account(),
        )->input ?? '';

    expect($script)
        ->toContain(
            'stat -c \'%U:%G\' "$directory")" != "$managed_user:$managed_group"',
            'stat -c %U:%G /opt/orbit/composer/composer.json)" = "$managed_user:$managed_group"',
        )
        ->not->toContain('orbit:orbit');
});

it('keeps app development directories and Caddy traversal ACLs role-owned', function (): void {
    expect(class_exists(NodeRolePrerequisiteCommandFactory::class))->toBeTrue();

    $factory = new NodeRolePrerequisiteCommandFactory;
    $account = default_managed_user_account();
    $appDev = $factory->make(RoleName::AppDev, $account)->input ?? '';
    $appProd = $factory->make(RoleName::AppProd, $account)->input ?? '';
    $vpn = $factory->make(RoleName::Vpn, $account)->input ?? '';

    expect($appDev)
        ->toContain(
            'install -d -m 0755 -o "$managed_user" -g "$managed_group" "$managed_home/apps" "$managed_home/.orbit/worktrees"',
            'setfacl -m u:caddy:--x "$managed_home" "$managed_home/apps" "$managed_home/.orbit" "$managed_home/.orbit/worktrees"',
        )
        ->and($appProd)
        ->not->toContain('/home/orbit/apps', 'setfacl')->and($vpn)
        ->not->toContain('/home/orbit/apps', '/opt/orbit/composer', '/opt/orbit/vite-plus', '/opt/orbit/bun');
});

it('prints the fixed requirement for malformed os-release quotes', function (): void {
    $role = RoleName::Vpn;
    $script = new NodeRolePrerequisiteCommandFactory()->make($role, default_managed_user_account())->input ?? '';
    $preflight = Str::before($script, "export DEBIAN_FRONTEND=noninteractive\n");
    $requirement = UbuntuRelease::requirementTextFor(UbuntuRelease::forRole($role));
    $fixture = role_prerequisite_os_release_fixture("ID=\"ubuntu'\nVERSION_CODENAME=resolute\n");

    try {
        $process = new Process(role_prerequisite_process_arguments(
            $role,
            [],
            requirement: $requirement,
            releases: ['resolute'],
        ));
        $process->setEnv(['PATH' => getenv('PATH') ?: '']);
        $process->setInput(str_replace('/etc/os-release', $fixture, $preflight));
        $process->run();

        expect($process->isSuccessful())->toBeFalse()->and($process->getErrorOutput())->toContain($requirement);
    } finally {
        if (is_file($fixture)) {
            unlink($fixture);
        }
    }
});

it('rejects adversarial os-release values before the payload sentinel', function (string $contents): void {
    $role = RoleName::Vpn;
    $requirement = UbuntuRelease::requirementTextFor(UbuntuRelease::forRole($role));
    $payloadMarker = sys_get_temp_dir().'/orbit-role-payload-'.Str::uuid();
    $fixture = role_prerequisite_os_release_fixture(str_replace('__PAYLOAD_MARKER__', $payloadMarker, $contents));
    $mutationMarker = sys_get_temp_dir().'/orbit-role-mutation-'.Str::uuid();
    $script = new NodeRolePrerequisiteCommandFactory()->make($role, default_managed_user_account())->input ?? '';
    $preflight = Str::before($script, "export DEBIAN_FRONTEND=noninteractive\n");
    $process = new Process(role_prerequisite_process_arguments(
        $role,
        [],
        requirement: $requirement,
        releases: ['resolute'],
    ));
    $process->setEnv(['PATH' => getenv('PATH') ?: '']);
    $process->setInput(
        "mutation_marker={$mutationMarker}\n"
        .str_replace('/etc/os-release', $fixture, $preflight)
        ."printf 'mutation-marker\n' > \"\$mutation_marker\"\n",
    );

    try {
        $process->run();

        expect($process->isSuccessful())
            ->toBeFalse()
            ->and($process->getOutput())
            ->toBeEmpty()
            ->and($process->getErrorOutput())
            ->toBe($requirement."\n")
            ->and(file_exists($mutationMarker))
            ->toBeFalse()
            ->and(file_exists($payloadMarker))
            ->toBeFalse();
    } finally {
        if (is_file($fixture)) {
            unlink($fixture);
        }

        if (is_file($mutationMarker)) {
            unlink($mutationMarker);
        }

        if (is_file($payloadMarker)) {
            unlink($payloadMarker);
        }
    }
})->with([
    'duplicate ID supported then supported' => "ID=ubuntu\nID=ubuntu\nVERSION_CODENAME=resolute\n",
    'duplicate ID supported then unsupported' => "ID=ubuntu\nID=debian\nVERSION_CODENAME=resolute\n",
    'duplicate codename supported then supported' => "ID=ubuntu\nVERSION_CODENAME=resolute\nVERSION_CODENAME=resolute\n",
    'duplicate codename supported then unsupported' => "ID=ubuntu\nVERSION_CODENAME=resolute\nVERSION_CODENAME=jammy\n",
    'missing codename value' => "ID=ubuntu\nVERSION_CODENAME=\n",
    'empty ID value' => "ID=\nVERSION_CODENAME=resolute\n",
    'mismatched ID quotes' => "ID=\"ubuntu'\nVERSION_CODENAME=resolute\n",
    'unclosed codename quote' => "ID=ubuntu\nVERSION_CODENAME='resolute\n",
    'command substitution' => "ID=ubuntu\nVERSION_CODENAME=\$(__PAYLOAD_MARKER__)\n",
    'backticks' => "ID=ubuntu\nVERSION_CODENAME=`touch __PAYLOAD_MARKER__`\n",
    'semicolon' => "ID=ubuntu\nVERSION_CODENAME=resolute;touch __PAYLOAD_MARKER__\n",
]);

it('accepts bare, single quoted, double quoted, and final unterminated supported values', function (string $contents): void {
    $role = RoleName::Vpn;
    $requirement = UbuntuRelease::requirementTextFor(UbuntuRelease::forRole($role));
    $fixture = role_prerequisite_os_release_fixture($contents);
    $mutationMarker = sys_get_temp_dir().'/orbit-role-mutation-'.Str::uuid();
    $script = new NodeRolePrerequisiteCommandFactory()->make($role, default_managed_user_account())->input ?? '';
    $preflight = Str::before($script, "export DEBIAN_FRONTEND=noninteractive\n");
    $process = new Process(role_prerequisite_process_arguments(
        $role,
        [],
        requirement: $requirement,
        releases: ['resolute'],
    ));
    $process->setEnv(['PATH' => getenv('PATH') ?: '']);
    $process->setInput(
        "mutation_marker={$mutationMarker}\n"
        .str_replace('/etc/os-release', $fixture, $preflight)
        ."printf 'mutation-marker\n' > \"\$mutation_marker\"\n",
    );

    try {
        $process->run();

        expect($process->isSuccessful())
            ->toBeTrue($process->getErrorOutput())
            ->and(trim(file_get_contents($mutationMarker)))
            ->toBe('mutation-marker');
    } finally {
        if (is_file($fixture)) {
            unlink($fixture);
        }

        if (is_file($mutationMarker)) {
            unlink($mutationMarker);
        }
    }
})->with([
    'bare values' => "ID=ubuntu\nVERSION_CODENAME=resolute\n",
    'single quoted values' => "ID='ubuntu'\nVERSION_CODENAME='resolute'\n",
    'double quoted values' => "ID=\"ubuntu\"\nVERSION_CODENAME=\"resolute\"\n",
    'final unterminated value' => "ID=ubuntu\nVERSION_CODENAME=resolute",
]);

it('prepares the orbit Composer workspace with the managed path', function (RoleName $role): void {
    $script = new NodeRolePrerequisiteCommandFactory()->make($role, default_managed_user_account())->input ?? '';

    expect($script)
        ->toContain(
            'install -d -m 0755 /opt/orbit',
            'install -d -m 0755 -o "$managed_user" -g "$managed_group" /opt/orbit/composer',
            'test -f /opt/orbit/composer/composer.json',
            'test "$(stat -c %U:%G /opt/orbit/composer/composer.json)" = "$managed_user:$managed_group"',
            'if [ -L /opt/orbit/composer/composer.json ]; then',
            'composer_manifest=$(mktemp /opt/orbit/.composer.json.XXXXXX)',
            'chmod 0644 "$composer_manifest"',
            'chown "$managed_user":"$managed_group" "$composer_manifest"',
            'ln "$composer_manifest" /opt/orbit/composer/composer.json',
            '! -L /opt/orbit/composer/composer.json',
            'trap cleanup_composer_manifest EXIT',
            'trap - EXIT',
            'rm -f -- "$composer_manifest"',
            'revalidate',
            'COMPOSER_HOME=/opt/orbit/composer',
            '/usr/bin/composer --version --no-ansi',
            '{"require":{}}',
        )
        ->not->toContain('/home/orbit/.composer');
})->with([RoleName::AppDev, RoleName::AppProd]);

it('preserves the complete Vite Plus and Bun application-host runtime', function (RoleName $role): void {
    expect(class_exists(NodeRolePrerequisiteCommandFactory::class))->toBeTrue();

    $script = new NodeRolePrerequisiteCommandFactory()->make($role, default_managed_user_account())->input ?? '';
    $syntax = new Process(['bash', '-n']);
    $syntax->setInput($script);
    $syntax->run();

    expect($script)
        ->toContain(
            'VP_HOME=/opt/orbit/vite-plus',
            'https://vite.plus',
            'env setup',
            'env on',
            'env install lts',
            'env default lts',
            'install -g --node lts pnpm',
            'BUN_INSTALL=/opt/orbit/bun',
            'https://bun.com/install',
            '/usr/local/bin/vp',
            '/usr/local/bin/node',
            '/usr/local/bin/pnpm',
            '/usr/local/bin/npm',
            '/usr/local/bin/npx',
            '/usr/local/bin/bun',
            'export VP_HOME=/opt/orbit/vite-plus',
            'Orbit JavaScript runtime directory conflict:',
            'Orbit JavaScript runtime launcher conflict:',
            'Orbit JavaScript runtime link conflict:',
            'rollback_javascript_runtime()',
        )
        ->not
        ->toContain('vp env install bun', 'npm install -g', 'bun install')
        ->and($syntax->isSuccessful())
        ->toBeTrue($syntax->getErrorOutput());
})->with([
    'app development' => RoleName::AppDev,
    'app production' => RoleName::AppProd,
]);

it('propagates failures from both official runtime installer downloads', function (): void {
    expect(class_exists(NodeRolePrerequisiteCommandFactory::class))->toBeTrue();

    $script =
        new NodeRolePrerequisiteCommandFactory()->make(RoleName::AppDev, default_managed_user_account())->input ?? '';

    foreach (['https://vite.plus', 'https://bun.com/install'] as $installerUrl) {
        $installerLine = collect(preg_split('/\R/', $script))
            ->first(static fn (string $line): bool => str_contains($line, $installerUrl));

        expect($installerLine)
            ->toBeString()
            ->toContain('bash -o pipefail -lc');

        $failureCommand = preg_replace(
            pattern: '/^sudo -u orbit -H env \S+ /',
            replacement: '',
            subject: trim($installerLine),
        );
        $failureCommand = str_replace(
            search: "curl -fsSL {$installerUrl}",
            replace: 'false',
            subject: $failureCommand ?? '',
        );
        $failure = Process::fromShellCommandline($failureCommand);
        $failure->run();

        expect($failure->isSuccessful())->toBeFalse();
    }
});

it('guards managed JavaScript paths before publishing stable entry points', function (): void {
    $script =
        new NodeRolePrerequisiteCommandFactory()->make(RoleName::AppProd, default_managed_user_account())->input ?? '';
    $runtimeGuard = mb_strpos(haystack: $script, needle: 'Orbit JavaScript runtime directory conflict:');
    $runtimeCreation = mb_strpos(
        haystack: $script,
        needle: 'install -d -m 0755 /opt/orbit',
        offset: $runtimeGuard,
    );
    $candidateCreation = mb_strpos(
        haystack: $script,
        needle: 'launcher_candidates=$(mktemp -d "/usr/local/bin/.orbit-js-runtime.XXXXXX")',
    );
    $launcherGuard = mb_strpos(haystack: $script, needle: 'Orbit JavaScript runtime launcher conflict:');
    $bunGuard = mb_strpos(haystack: $script, needle: 'Orbit JavaScript runtime link conflict:');
    $launcherPublish = mb_strpos(haystack: $script, needle: 'mv "$candidate" "$launcher"');
    $bunPublish = mb_strpos(haystack: $script, needle: 'ln -s "$bun_binary" /usr/local/bin/bun');

    expect($script)->toContain(
        'stat -c \'%U:%G\' /opt/orbit',
        'stat -c \'%U:%G\' "$directory"',
        'stat -c \'%U:%G\' "$launcher"',
        'stat -c \'%a\' "$launcher"',
        'stat -c \'%U:%G\' /usr/local/bin/bun',
        'cmp -s "$launcher" "$candidate"',
        'published_paths=',
        'rollback_javascript_runtime()',
        'rm -f -- "$published_path"',
    );
    expect($runtimeGuard)->toBeInt()->toBeLessThan($runtimeCreation);
    expect($runtimeCreation)->toBeInt()->toBeLessThan($candidateCreation);
    expect($candidateCreation)->toBeInt()->toBeLessThan($launcherGuard);
    expect($launcherGuard)->toBeInt()->toBeLessThan($launcherPublish);
    expect($bunGuard)->toBeInt()->toBeLessThan($launcherPublish);
    expect($bunGuard)->toBeInt()->toBeLessThan($bunPublish);
    expect(substr_count(haystack: $script, needle: '> "$candidate"'))->toBe(1);
});

it('repairs existing managed runtime ownership without following symlinks before installers', function (): void {
    $script =
        new NodeRolePrerequisiteCommandFactory()->make(RoleName::AppDev, default_managed_user_account())->input ?? '';
    $repair = mb_strpos($script, 'chown -R --no-dereference "$managed_user:$managed_group"');
    $viteInstaller = mb_strpos($script, 'https://vite.plus');
    $bunInstaller = mb_strpos($script, 'https://bun.com/install');

    expect($repair)
        ->toBeInt()
        ->and($viteInstaller)
        ->toBeInt()
        ->toBeGreaterThan($repair)
        ->and($bunInstaller)
        ->toBeInt()
        ->toBeGreaterThan($repair)
        ->and($script)
        ->toContain(
            'sudo -u "$managed_user" -H env VP_HOME=/opt/orbit/vite-plus /usr/local/bin/vp --version',
            'sudo -u "$managed_user" -H env BUN_INSTALL=/opt/orbit/bun /usr/local/bin/bun --version',
        )
        ->and($script)
        ->toContain(
            'sudo -u "$managed_user" -H /usr/local/bin/node --version',
            'sudo -u "$managed_user" -H /usr/local/bin/pnpm --version',
            'sudo -u "$managed_user" -H /usr/local/bin/npm --version',
            'sudo -u "$managed_user" -H /usr/local/bin/npx --version',
        );
});

it('rejects foreign launchers before publishing stable entry points', function (): void {
    expect(class_exists(NodeRolePrerequisiteCommandFactory::class))->toBeTrue();

    $script =
        new NodeRolePrerequisiteCommandFactory()->make(RoleName::AppProd, default_managed_user_account())->input ?? '';
    $harness = role_javascript_runtime_harness($script, foreignLauncher: 'npm');

    try {
        expect($harness['process']->isSuccessful())
            ->toBeFalse()
            ->and($harness['process']->getErrorOutput())
            ->toContain("Orbit JavaScript runtime launcher conflict: {$harness['stableDirectory']}/npm")
            ->and(file_get_contents("{$harness['stableDirectory']}/npm"))
            ->toBe("foreign\n");

        foreach (['vp', 'node', 'pnpm', 'npx', 'bun'] as $binary) {
            expect("{$harness['stableDirectory']}/{$binary}")->not->toBeFile();
        }
    } finally {
        new Filesystem()->deleteDirectory($harness['root']);
    }
});

it('preserves exact launchers while rolling back new entry points after verification fails', function (): void {
    $script =
        new NodeRolePrerequisiteCommandFactory()->make(RoleName::AppDev, default_managed_user_account())->input ?? '';
    $harness = role_javascript_runtime_harness($script, failingRuntime: 'npx', exactLauncher: 'vp');

    try {
        expect($harness['process']->isSuccessful())
            ->toBeFalse()
            ->and(file_get_contents("{$harness['stableDirectory']}/vp"))
            ->toBe($harness['exactLauncherContents']);

        foreach (['node', 'pnpm', 'npm', 'npx', 'bun'] as $binary) {
            expect("{$harness['stableDirectory']}/{$binary}")->not->toBeFile();
        }
    } finally {
        new Filesystem()->deleteDirectory($harness['root']);
    }
});

it('materializes the Composer manifest and vendor bin directory idempotently', function (): void {
    $harness = role_composer_harness();

    try {
        expect($harness['first']->isSuccessful())
            ->toBeTrue($harness['first']->getErrorOutput())
            ->and(trim(file_get_contents("{$harness['composer']}/composer.json")))
            ->toBe('{"require":{}}')
            ->and("{$harness['composer']}/composer.json")
            ->toBeFile()
            ->and(fileperms("{$harness['composer']}/composer.json") & 0o777)
            ->toBe(0o644)
            ->and(posix_getpwuid(fileowner("{$harness['composer']}/composer.json"))['name'])
            ->toBe($harness['owner'])
            ->and(posix_getgrgid(filegroup("{$harness['composer']}/composer.json"))['name'])
            ->toBe($harness['group'])
            ->and("{$harness['composer']}/vendor/bin")
            ->toBeDirectory()
            ->and($harness['second']->isSuccessful())
            ->toBeTrue($harness['second']->getErrorOutput())
            ->and(iterator_count(new Filesystem()->files($harness['root'])))
            ->toBe(1);
    } finally {
        new Filesystem()->deleteDirectory($harness['root']);
    }
});

it('rejects Composer manifest file, directory, and symlink conflicts without overwrite', function (): void {
    foreach (['file', 'directory', 'symlink'] as $conflict) {
        $harness = role_composer_harness(conflict: $conflict);

        try {
            expect($harness['first']->isSuccessful())
                ->toBeFalse()
                ->and($harness['first']->getErrorOutput())
                ->toContain('Orbit Composer manifest conflict:')
                ->and(file_exists($harness['manifest']) || is_link($harness['manifest']))
                ->toBeTrue();

            match ($conflict) {
                'file' => expect(file_get_contents($harness['manifest']))->toBe("foreign\n"),
                'directory' => expect($harness['manifest'])->toBeDirectory(),
                'symlink' => expect(readlink($harness['manifest']))->toBe('/tmp/foreign'),
            };
        } finally {
            new Filesystem()->deleteDirectory($harness['root']);
        }
    }
});

it('cleans Composer temp candidates after success, failure, and publication races', function (): void {
    foreach (['success', 'failure', 'race'] as $mode) {
        $harness = role_composer_harness(mode: $mode);

        try {
            expect($harness['first']->isSuccessful())->toBe($mode !== 'failure');
            expect(glob("{$harness['root']}/.composer.json.*"))->toBeEmpty();
            if ($mode === 'race') {
                expect(trim(file_get_contents($harness['manifest'])))->toBe('{"require":{"winner":true}}');
            }
        } finally {
            new Filesystem()->deleteDirectory($harness['root']);
        }
    }
});

/**
 * @return array{root: string, stableDirectory: string, exactLauncherContents: string, process: Process}
 */
function role_javascript_runtime_harness(
    string $script,
    ?string $foreignLauncher = null,
    ?string $failingRuntime = null,
    ?string $exactLauncher = null,
): array {
    $filesystem = new Filesystem;
    $root = sys_get_temp_dir().'/orbit-role-javascript-runtime-'.Str::random(16);
    $sourceDirectory = "{$root}/source";
    $stableDirectory = "{$root}/stable";
    $filesystem->makeDirectory($sourceDirectory, 0o700, recursive: true);
    $filesystem->makeDirectory($stableDirectory, 0o700, recursive: true);

    foreach (['vp', 'node', 'pnpm', 'npm', 'npx', 'bun'] as $binary) {
        $exitCode = $binary === $failingRuntime ? 1 : 0;
        $filesystem->put("{$sourceDirectory}/{$binary}", "#!/bin/sh\nexit {$exitCode}\n");
        chmod(filename: "{$sourceDirectory}/{$binary}", permissions: 0o755);
    }

    $exactLauncherContents = '';

    if ($exactLauncher !== null) {
        $exactLauncherContents = "#!/bin/sh\nexport VP_HOME=/opt/orbit/vite-plus\nexec \"{$sourceDirectory}/{$exactLauncher}\" \"\$@\"\n";
        $filesystem->put("{$stableDirectory}/{$exactLauncher}", $exactLauncherContents);
        chmod(filename: "{$stableDirectory}/{$exactLauncher}", permissions: 0o755);
    }

    if ($foreignLauncher !== null) {
        $filesystem->put("{$stableDirectory}/{$foreignLauncher}", "foreign\n");
        chmod(filename: "{$stableDirectory}/{$foreignLauncher}", permissions: 0o755);
    }

    $start = mb_strpos(
        haystack: $script,
        needle: 'launcher_candidates=$(mktemp -d "/usr/local/bin/.orbit-js-runtime.XXXXXX")',
    );
    $end = is_int($start) ? mb_strpos(haystack: $script, needle: 'trap - EXIT', offset: $start) : false;

    if (! is_int($start) || ! is_int($end)) {
        throw new RuntimeException('Could not isolate the JavaScript runtime publication block.');
    }

    $publicationScript = mb_substr(
        string: $script,
        start: $start,
        length: $end - $start + mb_strlen('trap - EXIT'),
    );
    $owner = posix_getpwuid(fileowner($stableDirectory));
    $group = posix_getgrgid(filegroup($stableDirectory));

    if (! is_array($owner) || ! is_array($group)) {
        throw new RuntimeException('Could not resolve the JavaScript runtime harness owner.');
    }

    $publicationScript = str_replace(
        ['/opt/orbit/vite-plus/bin', '/usr/local/bin', "'root:root'", 'chown root:root "$candidate"'],
        [$sourceDirectory, $stableDirectory, "'{$owner['name']}:{$group['name']}'", 'true'],
        $publicationScript,
    );
    $process = new Process(['bash', '-seu']);
    $process->setInput("bun_binary={$sourceDirectory}/bun\n{$publicationScript}\n");
    $process->run();

    return [
        'root' => $root,
        'stableDirectory' => $stableDirectory,
        'exactLauncherContents' => $exactLauncherContents,
        'process' => $process,
    ];
}

/**
 * @return array{root: string, composer: string, manifest: string, owner: string, group: string, first: Process, second: Process}
 * @mago-expect lint:halstead The harness exercises publication success, rollback, and race recovery against the rendered script.
 */
function role_composer_harness(?string $conflict = null, string $mode = 'success'): array
{
    $filesystem = new Filesystem;
    $root = sys_get_temp_dir().'/orbit-role-composer-'.Str::random(16);
    $composer = "{$root}/composer";
    $manifest = "{$composer}/composer.json";
    $filesystem->makeDirectory($root, 0o755, true);

    if ($conflict !== null) {
        $filesystem->makeDirectory($composer, 0o755);
    }

    $script =
        new NodeRolePrerequisiteCommandFactory()->make(RoleName::AppDev, default_managed_user_account())->input ?? '';
    $start = mb_strpos(
        haystack: $script,
        needle: 'install -d -m 0755 -o "$managed_user" -g "$managed_group" /opt/orbit/composer',
    );
    if (! is_int($start)) {
        $start = mb_strpos(haystack: $script, needle: 'install -d -m 0755 -o orbit -g orbit /opt/orbit/composer');
    }
    $end = mb_strpos(haystack: $script, needle: '--no-ansi', offset: $start === false ? 0 : $start);
    $fragment = is_int($start) && is_int($end) ? mb_substr($script, $start, $end - $start) : '';
    $fragment = str_replace(['/opt/orbit/composer', '/opt/orbit'], [$composer, $root], $fragment);
    $owner = posix_getpwuid(fileowner($root))['name'] ?? get_current_user();
    $group = posix_getgrgid(filegroup($root))['name'] ?? $owner;
    $fragment = str_replace(
        [
            '-o "$managed_user" -g "$managed_group"',
            '-o orbit -g orbit',
            '"$managed_user":"$managed_group"',
            '"$managed_user:$managed_group"',
            'orbit:orbit',
        ],
        [
            "-o {$owner} -g {$group}",
            "-o {$owner} -g {$group}",
            "{$owner}:{$group}",
            "{$owner}:{$group}",
            "{$owner}:{$group}",
        ],
        $fragment,
    );
    $fragment = str_replace(
        [
            'managed_user=orbit',
            'managed_group=orbit',
            'managed_home=/home/orbit',
            'sudo -u "$managed_user" -H env',
            'sudo -u orbit -H env',
        ],
        ["managed_user={$owner}", "managed_group={$group}", "managed_home={$root}", 'env', 'env'],
        $fragment,
    );
    $stub = "{$root}/composer-stub";
    $filesystem->put($stub, "#!/bin/sh\nexit 0\n");
    chmod(filename: $stub, permissions: 0o755);
    $fragment = str_replace('/usr/bin/composer', $stub, $fragment);

    if ($mode === 'failure') {
        $manifestWrite = <<<'BASH'
            printf '%s\n' '{"require":{}}' > "$composer_manifest"
            BASH;

        if (! str_contains($fragment, $manifestWrite)) {
            throw new RuntimeException('Could not inject the Composer manifest failure.');
        }

        $fragment = str_replace(search: $manifestWrite, replace: 'false', subject: $fragment);
    }

    $ln = null;

    if ($conflict === 'file') {
        $filesystem->put($manifest, "foreign\n");
        $fragment = str_replace(
            search: "= {$owner}:{$group}",
            replace: '= foreign:foreign',
            subject: $fragment,
        );
    }

    if ($conflict === 'directory') {
        $filesystem->makeDirectory($manifest);
    }

    if ($conflict === 'symlink') {
        symlink('/tmp/foreign', $manifest);
    }

    if ($mode === 'race') {
        $ln = "{$root}/ln";
        $filesystem->put(
            $ln,
            "#!/bin/sh\nif [ \"\$2\" = \"{$manifest}\" ]; then printf '%s\\n' '{\"require\":{\"winner\":true}}' > \"\$2\"; fi\nexec /usr/bin/ln \"\$@\"\n",
        );
        chmod(filename: $ln, permissions: 0o755);
    }

    $path = $mode === 'race' ? dirname($ln).':'.getenv('PATH') : getenv('PATH');
    $run = function () use ($fragment, $path): Process {
        $process = new Process(['bash', '-seu']);
        $process->setEnv(['PATH' => $path]);
        $process->setInput($fragment);
        $process->run();

        return $process;
    };
    $first = $run();
    $second = $run();

    return compact('root', 'composer', 'manifest', 'owner', 'group', 'first', 'second');
}

function role_prerequisite_os_release_fixture(string $contents): string
{
    $path = sys_get_temp_dir().'/orbit-role-os-release-'.Str::uuid();
    file_put_contents($path, $contents);

    return $path;
}

/**
 * @param  list<string>  $packages
 * @param  list<string>|null  $releases
 * @return list<string>
 */
function role_prerequisite_process_arguments(
    RoleName $role,
    array $packages,
    bool $includeShell = true,
    ?string $requirement = null,
    ?array $releases = null,
): array {
    $account = default_managed_user_account();
    $releases ??= array_map(
        static fn (UbuntuRelease $release): string => $release->value,
        UbuntuRelease::forRole($role),
    );
    $arguments = [
        $role->value,
        $account->user,
        $account->group,
        $account->home,
        (string) count($releases),
        $requirement ?? UbuntuRelease::requirementTextFor(UbuntuRelease::forRole($role)),
        ...$releases,
        ...$packages,
    ];

    if (! $includeShell) {
        return $arguments;
    }

    return ['bash', '-seu', '--', ...$arguments];
}

/** @return list<string> */
function role_prerequisite_packages(\App\Infrastructure\Ssh\RemoteCommand $command): array
{
    $arguments = $command->arguments;
    $releaseCount = (int) $arguments[8];
    $packageOffset = 10 + $releaseCount;

    return array_slice($arguments, $packageOffset);
}

function default_managed_user_account(): ManagedUserAccount
{
    return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
}

function nondefault_managed_user_account(): ManagedUserAccount
{
    return new ManagedUserAccount('nckrtl', 'nckrtl', '/srv/users/nckrtl');
}
