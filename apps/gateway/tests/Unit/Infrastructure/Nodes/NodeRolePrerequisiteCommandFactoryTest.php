<?php

declare(strict_types=1);

use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\UbuntuRelease;
use App\Infrastructure\Nodes\Roles\NodeRolePrerequisiteCommandFactory;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('uses fixed package lists for every role', function (): void {
    expect(class_exists(NodeRolePrerequisiteCommandFactory::class))->toBeTrue();

    $factory = new NodeRolePrerequisiteCommandFactory;
    $account = default_managed_user_account();
    expect(role_prerequisite_packages($factory->make(new Node, RoleName::AppDev, $account)))
        ->toBe(['acl', 'attr', 'caddy', 'composer', 'docker.io', 'git', 'openssl', 'php-curl', 'php-xml', 'unzip']);

    expect(role_prerequisite_packages($factory->make(new Node, RoleName::AppDev, $account)))
        ->toBe(['acl', 'attr', 'caddy', 'composer', 'docker.io', 'git', 'openssl', 'php-curl', 'php-xml', 'unzip'])
        ->and(role_prerequisite_packages($factory->make(new Node, RoleName::AppProd, $account)))
        ->toBe(['acl', 'attr', 'caddy', 'composer', 'docker.io', 'git', 'openssl', 'php-curl', 'php-xml', 'unzip'])
        ->and(role_prerequisite_packages($factory->make(new Node, RoleName::WebSocket, $account)))
        ->toBe(['caddy', 'composer', 'git', 'openssl', 'php-curl', 'php-xml'])
        ->and(role_prerequisite_packages($factory->make(new Node, RoleName::Vpn, $account)))
        ->toBe(['dnsmasq', 'openssl'])
        ->and(role_prerequisite_packages($factory->make(new Node, RoleName::Database, $account)))
        ->toBe(['docker.io'])
        ->and($factory->make(new Node, RoleName::Gateway, $account)->arguments)
        ->toBe(['true']);
});

it('uses healthy Docker CE as the private Docker prerequisite without allowing removals', function (): void {
    $script =
        new NodeRolePrerequisiteCommandFactory()->make(
            new Node,
            RoleName::AppProd,
            default_managed_user_account(),
        )->input ?? '';
    $preflight = Str::before($script, 'if { [ -e /opt/orbit ]');
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
    $script =
        new NodeRolePrerequisiteCommandFactory()->make(new Node, $role, default_managed_user_account())->input ?? '';
    $preflight = Str::before($script, "export DEBIAN_FRONTEND=noninteractive\n");
    $marker = sys_get_temp_dir().'/orbit-role-marker-'.Str::uuid();
    $requirement = UbuntuRelease::unsupportedText();
    $supportedReleases = array_map(
        static fn (UbuntuRelease $release): string => $release->value,
        UbuntuRelease::forRole($role),
    );
    $unsupportedContents = "ID=ubuntu\nVERSION_CODENAME=unsupported\n";
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
            ->toBe(UbuntuRelease::unsupportedText('ubuntu', 'unsupported')."\n")
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
    $command = new NodeRolePrerequisiteCommandFactory()->make(new Node, RoleName::AppDev, $account);

    expect(array_slice($command->arguments, 0, 11))
        ->toBe([
            'sudo',
            'bash',
            '-seu',
            '--',
            'app-dev',
            'nckrtl',
            'nckrtl',
            '/srv/users/nckrtl',
            'ubuntu',
            UbuntuRelease::unsupportedText(),
            '1',
        ])
        ->and($command->input ?? '')
        ->toContain(
            'managed_user=$1',
            'managed_group=$2',
            'managed_home=$3',
            'install -d -m 0755 -o "$managed_user" -g "$managed_group" "$managed_home/apps" "$managed_home/.orbit/worktrees"',
        )
        ->not->toContain('/home/orbit/apps', '/home/orbit/.orbit/worktrees', 'sudo -u orbit');
});

it('uses the managed account for ownership validation on shared prerequisites', function (): void {
    $script =
        new NodeRolePrerequisiteCommandFactory()->make(
            new Node,
            RoleName::AppDev,
            nondefault_managed_user_account(),
        )->input ?? '';

    expect($script)
        ->toContain(
            'stat -c \'%U:%G\' "$directory")" != "$managed_user:$managed_group"',
        )
        ->not->toContain('orbit:orbit');
});

it('creates app development directories without mutating Caddy traversal ACLs', function (): void {
    expect(class_exists(NodeRolePrerequisiteCommandFactory::class))->toBeTrue();

    $factory = new NodeRolePrerequisiteCommandFactory;
    $account = default_managed_user_account();
    $appDev = $factory->make(new Node, RoleName::AppDev, $account)->input ?? '';
    $appProd = $factory->make(new Node, RoleName::AppProd, $account)->input ?? '';
    $vpn = $factory->make(new Node, RoleName::Vpn, $account)->input ?? '';

    expect($appDev)
        ->toContain(
            'install -d -m 0755 -o "$managed_user" -g "$managed_group" "$managed_home/apps" "$managed_home/.orbit/worktrees"',
        )
        ->not->toContain('setfacl')->and($appProd)
        ->not->toContain('/home/orbit/apps', 'setfacl')->and($vpn)
        ->not->toContain('/home/orbit/apps', '/opt/orbit/composer', '/opt/orbit/vite-plus', '/opt/orbit/bun');
});

it('prints a generic operating system error for malformed os-release quotes', function (): void {
    $role = RoleName::Vpn;
    $script =
        new NodeRolePrerequisiteCommandFactory()->make(new Node, $role, default_managed_user_account())->input ?? '';
    $preflight = Str::before($script, "export DEBIAN_FRONTEND=noninteractive\n");
    $requirement = UbuntuRelease::unsupportedText();
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
    $requirement = UbuntuRelease::unsupportedText();
    $payloadMarker = sys_get_temp_dir().'/orbit-role-payload-'.Str::uuid();
    $fixture = role_prerequisite_os_release_fixture(str_replace('__PAYLOAD_MARKER__', $payloadMarker, $contents));
    $mutationMarker = sys_get_temp_dir().'/orbit-role-mutation-'.Str::uuid();
    $script =
        new NodeRolePrerequisiteCommandFactory()->make(new Node, $role, default_managed_user_account())->input ?? '';
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
    $requirement = UbuntuRelease::unsupportedText();
    $fixture = role_prerequisite_os_release_fixture($contents);
    $mutationMarker = sys_get_temp_dir().'/orbit-role-mutation-'.Str::uuid();
    $script =
        new NodeRolePrerequisiteCommandFactory()->make(new Node, $role, default_managed_user_account())->input ?? '';
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

it('keeps Bun and system prerequisites outside the Tool manager owners', function (RoleName $role): void {
    $script = new NodeRolePrerequisiteCommandFactory()->make(new Node, $role, default_managed_user_account())->input ?? '';
    $syntax = new Process(['bash', '-n']);
    $syntax->setInput($script);
    $syntax->run();

    expect($script)->toContain(
        'BUN_INSTALL=/opt/orbit/bun',
        'https://bun.com/install',
        'bash -o pipefail -c',
        'apt-get install --yes --no-install-recommends --no-remove -- "$@"',
        'sudo -u "$managed_user" -H env BUN_INSTALL=/opt/orbit/bun /usr/local/bin/bun --version',
    )->and($syntax->isSuccessful())->toBeTrue($syntax->getErrorOutput());

    foreach (['VP_HOME', 'vite-plus', 'https://vite.plus', '/usr/local/bin/node', '/usr/local/bin/pnpm', '/usr/local/bin/npm', '/usr/local/bin/npx', '/opt/orbit/composer', '/usr/bin/composer'] as $managerSetup) {
        expect($script)->not->toContain($managerSetup);
    }
})->with([RoleName::AppDev, RoleName::AppProd]);

it('guards Bun paths and repairs managed ownership before installing', function (): void {
    $script = new NodeRolePrerequisiteCommandFactory()->make(new Node, RoleName::AppDev, nondefault_managed_user_account())->input ?? '';
    $directoryGuard = mb_strpos($script, 'Orbit JavaScript runtime directory conflict:');
    $creation = mb_strpos($script, 'install -d -m 0755 /opt/orbit');
    $repair = mb_strpos($script, 'chown -R --no-dereference "$managed_user:$managed_group"');
    $installer = mb_strpos($script, 'https://bun.com/install');
    $linkGuard = mb_strpos($script, 'Orbit JavaScript runtime link conflict:');
    $publication = mb_strpos($script, 'ln -s "$bun_binary" /usr/local/bin/bun');

    expect($script)->toContain(
        'stat -c \'%U:%G\' /opt/orbit',
        'stat -c \'%U:%G\' "$directory"',
        'stat -c \'%U:%G\' /usr/local/bin/bun',
        'readlink /usr/local/bin/bun',
        'rollback_bun_runtime()',
        'rm -f -- /usr/local/bin/bun',
    );
    expect($directoryGuard)->toBeInt()->toBeLessThan($creation);
    expect($creation)->toBeInt()->toBeLessThan($repair);
    expect($repair)->toBeInt()->toBeLessThan($installer);
    expect($installer)->toBeInt()->toBeLessThan($linkGuard);
    expect($linkGuard)->toBeInt()->toBeLessThan($publication);
});

it('propagates a failed Bun installer download', function (): void {
    $script = new NodeRolePrerequisiteCommandFactory()->make(new Node, RoleName::AppDev, default_managed_user_account())->input ?? '';
    $installer = collect(preg_split('/\R/', $script))->first(static fn (string $line): bool => str_contains($line, 'https://bun.com/install'));
    expect($installer)->toBeString()->toContain('bash -o pipefail -c');
    $failureCommand = str_replace(
        ['sudo -u "$managed_user" -H ', 'curl -fsSL https://bun.com/install'],
        ['', 'false'],
        trim($installer),
    );
    $process = Process::fromShellCommandline($failureCommand);
    $process->run();

    expect($process->isSuccessful())->toBeFalse();
});

it('publishes and verifies Bun without touching manager launchers', function (): void {
    $harness = role_bun_runtime_harness();

    try {
        expect($harness['process']->isSuccessful())->toBeTrue($harness['process']->getErrorOutput())
            ->and(is_link($harness['link']))->toBeTrue()
            ->and(readlink($harness['link']))->toBe($harness['binary'])
            ->and($harness['process']->getOutput())->toBe("bun-version\n")
            ->and(file_get_contents($harness['unrelated']))->toBe("manager-owned\n");
    } finally {
        new Filesystem()->deleteDirectory($harness['root']);
    }
});

it('refuses foreign Bun entry points without replacing them', function (string $existing): void {
    $harness = role_bun_runtime_harness(existing: $existing);

    try {
        expect($harness['process']->isSuccessful())->toBeFalse()
            ->and($harness['process']->getErrorOutput())->toContain('Orbit JavaScript runtime link conflict:')
            ->and($harness['process']->getOutput())->toBeEmpty()
            ->and(file_get_contents($harness['unrelated']))->toBe("manager-owned\n");

        match ($existing) {
            'file' => expect(file_get_contents($harness['link']))->toBe("foreign\n"),
            'symlink' => expect(readlink($harness['link']))->toBe($harness['unrelated']),
        };
    } finally {
        new Filesystem()->deleteDirectory($harness['root']);
    }
})->with(['file', 'symlink']);

it('rolls back only a newly published Bun link when verification fails', function (string $existing): void {
    $harness = role_bun_runtime_harness(existing: $existing, exitCode: 23);

    try {
        expect($harness['process']->getExitCode())->toBe(23)
            ->and(is_link($harness['link']))->toBe($existing === 'exact')
            ->and(file_get_contents($harness['unrelated']))->toBe("manager-owned\n");

        if ($existing === 'exact') {
            expect(readlink($harness['link']))->toBe($harness['binary']);
        }
    } finally {
        new Filesystem()->deleteDirectory($harness['root']);
    }
})->with(['none', 'exact']);

/**
 * @return array{root: string, binary: string, link: string, unrelated: string, process: Process}
 */
function role_bun_runtime_harness(string $existing = 'none', int $exitCode = 0): array
{
    $filesystem = new Filesystem;
    $root = sys_get_temp_dir().'/orbit-role-bun-'.Str::uuid();
    $filesystem->makeDirectory("{$root}/stable", 0o755, true);
    $filesystem->makeDirectory("{$root}/orbit/bun/bin", 0o755, true);
    $binary = "{$root}/orbit/bun/bin/bun";
    $link = "{$root}/stable/bun";
    $unrelated = "{$root}/stable/vp";
    $filesystem->put($binary, "#!/bin/sh\nprintf 'bun-version\\n'\nexit {$exitCode}\n");
    chmod($binary, 0o755);
    $filesystem->put($unrelated, "manager-owned\n");

    match ($existing) {
        'none' => null,
        'exact' => symlink($binary, $link),
        'file' => $filesystem->put($link, "foreign\n"),
        'symlink' => symlink($unrelated, $link),
        default => throw new InvalidArgumentException('Unknown Bun fixture entry point.'),
    };

    $script = new NodeRolePrerequisiteCommandFactory()->make(new Node, RoleName::AppDev, default_managed_user_account())->input ?? '';
    $start = mb_strpos($script, 'if { [ -e /opt/orbit ]');
    if (! is_int($start)) {
        throw new RuntimeException('Could not isolate the Bun runtime block.');
    }
    $script = mb_substr($script, $start);
    $script = str_replace(
        'sudo -u "$managed_user" -H env BUN_INSTALL=/opt/orbit/bun bash -o pipefail -c \'curl -fsSL https://bun.com/install | bash\'',
        'true',
        $script,
        $installers,
    );
    $script = str_replace('sudo -u "$managed_user" -H env BUN_INSTALL=', 'env BUN_INSTALL=', $script, $probes);
    if ($installers !== 1 || $probes !== 1 || str_contains($script, 'sudo') || str_contains($script, 'curl')) {
        throw new RuntimeException('Could not isolate every Bun runtime fixture command.');
    }
    $owner = posix_getpwuid(fileowner($root))['name'];
    $group = posix_getgrgid(filegroup($root))['name'];
    $script = str_replace(
        ['/opt/orbit', '/usr/local/bin', "'root:root'"],
        ["{$root}/orbit", "{$root}/stable", "'{$owner}:{$group}'"],
        $script,
    );
    $process = new Process(['bash', '-seu']);
    $process->setInput("managed_user={$owner}\nmanaged_group={$group}\n{$script}");
    $process->run();

    return compact('root', 'binary', 'link', 'unrelated', 'process');
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
        'ubuntu',
        $requirement ?? UbuntuRelease::unsupportedText(),
        (string) count($releases),
        ...$releases,
        ...$packages,
    ];

    if (! $includeShell) {
        return $arguments;
    }

    return ['bash', '-seu', '--', ...$arguments];
}

/** @return list<string> */
function role_prerequisite_packages(RemoteCommand $command): array
{
    $arguments = $command->arguments;
    $releaseCount = (int) $arguments[10];
    $packageOffset = 11 + $releaseCount;

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
