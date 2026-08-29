<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\AppProd\AppProdSshExecutor;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Symfony\Component\Process\Process;
use Tests\Support\AppDevFakeSshExecutor;

it('converges the pinned Sury Noble or Resolute source before package installation', function (): void {
    $transport = new AppDevFakeSshExecutor;

    new RemotePhpPackageManager()->installForAppDev(
        php_package_node(RoleName::AppDev),
        collect(['8.4']),
        php_package_app_dev_ssh($transport),
    );

    expect($transport->commands)->toHaveCount(2);

    $sourceCommand = $transport->commands[0];
    $installCommand = $transport->commands[1];
    $sourceScript = $sourceCommand->input ?? '';
    $installScript = $installCommand->input ?? '';
    $firstSourceMutation = mb_strpos(haystack: $sourceScript, needle: 'sudo install');
    $releaseGuard = mb_strpos(
        haystack: $sourceScript,
        needle: "selected_codename=''",
    );

    expect(array_slice(array: $sourceCommand->arguments, offset: 0, length: 16))
        ->toBe([
            'bash',
            '-seu',
            '--',
            'ubuntu',
            'Orbit requires Ubuntu 24.04 Noble or Ubuntu 26.04 Resolute.',
            '2',
            'noble',
            'resolute',
            'https://packages.sury.org/php/',
            'https://packages.sury.org/php/apt.gpg',
            '/usr/share/keyrings/orbit-sury-php.gpg',
            '/etc/apt/sources.list.d/orbit-php.sources',
            'b486fd5488185c4c46467960fa69c53d5085fec492cf76b9eaf3db33561c9d7c',
            '15058500A0235D97F5D10063B188E2B695BD4743',
            '45BEA3E529112086C622F8A4B214EAC28059B8AC',
            '15058500A0235D97F5D10063B188E2B695BD4743',
        ])
        ->and($sourceScript)
        ->toContain(
            '[ ! -r /etc/os-release ]',
            'while IFS= read -r os_line',
            '[ "$os_id" != "$expected_id" ]',
            "selected_codename=''",
            'ppa\\.launchpadcontent\\.net/ondrej/php',
            'packages\\.sury\\.org/php',
            '[ ! -e "$managed_path" ] && [ ! -L "$managed_path" ]',
            'umask 077',
            'mktemp -d',
            'gnupg_home="$work_directory/gnupg"',
            'install -d -m 0700 -- "$gnupg_home"',
            'curl --fail --silent --show-error --location --proto \'=https\' --tlsv1.2',
            'sha256sum',
            'GNUPGHOME="$gnupg_home" gpg --batch --with-colons --show-keys',
            'Types: deb',
            'Signed-By:',
            'trap restore_source EXIT',
            'install -m 0644 -o root -g root',
            'mv --',
            'apt-get -o DPkg::Lock::Timeout=300 update',
            'apt-cache policy',
            'apt-cache madison',
            'expected_origin="${expected_uri%/} $selected_codename/main"',
        )
        ->not->toContain('apt-key', 'add-apt-repository', 'ppa:ondrej/php')->and(
            $releaseGuard,
        )->toBeInt()->toBeLessThan($firstSourceMutation)->and(
            $firstSourceMutation,
        )->toBeInt()->and(array_values(array_filter(
            $sourceCommand->arguments,
            static fn (string $argument): bool => str_starts_with($argument, 'php'),
        )))->toBe(array_values(array_filter(
            $installCommand->arguments,
            static fn (string $argument): bool => str_starts_with($argument, 'php'),
        )))->and(array_slice(
            array: $installCommand->arguments,
            offset: 0,
            length: 9,
        ))->toBe([
            'bash',
            '-seu',
            '--',
            '8.4',
            'app-dev',
            'Orbit requires Ubuntu 24.04 Noble or Ubuntu 26.04 Resolute.',
            '2',
            'noble',
            'resolute',
        ])->and($installScript)->toContain('dpkg-query', 'apt-get -o DPkg::Lock::Timeout=300 install')
        ->not->toContain('. /etc/os-release', '[ -x "/usr/sbin/php-fpm$version" ]', 'apt-key', 'add-apt-repository');
});

it('selects only the validated release suite for each role policy', function (
    RoleName $role,
    string $requirement,
    array $suites,
): void {
    $transport = new AppDevFakeSshExecutor;

    new RemotePhpPackageManager()->installForAppDev(
        php_package_node($role),
        collect(['8.5']),
        php_package_app_dev_ssh($transport),
    );

    $source = $transport->commands[0]->input ?? '';
    expect($source)
        ->toContain(
            "selected_codename=''",
            '"$selected_codename"',
            'expected_origin="${expected_uri%/} $selected_codename/main"',
        )
        ->and($transport->commands[0]->arguments)
        ->toContain(...$suites)
        ->and($requirement)
        ->toBeString();
})->with([
    'app-dev Noble and Resolute' => [
        RoleName::AppDev,
        'Orbit requires Ubuntu 24.04 Noble or Ubuntu 26.04 Resolute.',
        ['noble', 'resolute'],
    ],
]);

it('rejects app-prod Noble before package inspection or installation', function (): void {
    $transport = new AppDevFakeSshExecutor;
    new RemotePhpPackageManager()->installForAppProd(
        php_package_node(RoleName::AppProd),
        collect(['8.5']),
        php_package_app_prod_ssh($transport),
    );
    $root = sys_get_temp_dir().'/orbit-php-prod-'.bin2hex(random_bytes(6));
    try {
        mkdir($root.'/bin', 0777, true);
        mkdir($root.'/etc', 0777, true);
        file_put_contents($root.'/etc/os-release', "ID=ubuntu\nVERSION_CODENAME=noble\n");
        $log = $root.'/calls.log';
        foreach (['dpkg-query', 'apt-get'] as $tool) {
            file_put_contents(
                $root.'/bin/'.$tool,
                "#!/usr/bin/env bash\nprintf '%s\\n' {$tool} >> ".escapeshellarg($log)."\nexit 1\n",
            );
            chmod($root.'/bin/'.$tool, 0755);
        }
        $script = str_replace('/etc/os-release', $root.'/etc/os-release', $transport->commands[1]->input ?? '');
        $process = new Process(['bash', '-seu', '--', ...array_slice($transport->commands[1]->arguments, 3)], $root, [
            'PATH' => $root.'/bin:'.getenv('PATH'),
        ]);
        $process->setInput($script);
        $process->run();
        expect($process->getExitCode())
            ->not->toBe(0)->and(is_file($log) ? file_get_contents($log) : '')
            ->not->toContain('dpkg-query', 'apt-get');
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('executes direct app-prod PHP source convergence on Resolute', function (): void {
    $transport = new AppDevFakeSshExecutor;
    new RemotePhpPackageManager()->installForAppProd(
        php_package_node(RoleName::AppProd),
        collect(['8.5']),
        php_package_app_prod_ssh($transport),
    );

    $root = sys_get_temp_dir().'/orbit-php-prod-resolute-'.bin2hex(random_bytes(6));
    try {
        mkdir($root.'/bin', 0777, true);
        mkdir($root.'/etc/apt/sources.list.d', 0777, true);
        mkdir($root.'/usr/share/keyrings', 0777, true);
        file_put_contents($root.'/etc/os-release', "ID=ubuntu\nVERSION_CODENAME=resolute\n");
        $script = str_replace(
            ['/etc/os-release', '/etc/apt', '/usr/share/keyrings'],
            [$root.'/etc/os-release', $root.'/etc/apt', $root.'/usr/share/keyrings'],
            $transport->commands[0]->input ?? '',
        );
        $arguments = array_map(
            static fn (string $argument): string => str_replace(
                ['/etc/os-release', '/etc/apt', '/usr/share/keyrings'],
                [$root.'/etc/os-release', $root.'/etc/apt', $root.'/usr/share/keyrings'],
                $argument,
            ),
            array_slice($transport->commands[0]->arguments, 3),
        );
        php_package_write_source_binaries($root, 'resolute', null);
        $process = new Process(['bash', '-seu', '--', ...$arguments], $root, ['PATH' => $root.'/bin:'.getenv('PATH')]);
        $process->setInput($script);
        $process->run();
        expect($process->getExitCode())
            ->toBe(0)
            ->and(file_get_contents($root.'/etc/apt/sources.list.d/orbit-php.sources'))
            ->toContain('Suites: resolute');
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('rejects invalid release metadata before mutation markers', function (string $metadata): void {
    $source = <<<'BASH'
        selected_codename=''
        for allowed_codename in "${allowed_codenames[@]}"; do
            if [ "${VERSION_CODENAME:-}" = "$allowed_codename" ]; then selected_codename=$allowed_codename; fi
        done
        if [ -z "$selected_codename" ]; then exit 1; fi
        sudo install -m 0644 candidate source
        BASH;

    expect($source)->toContain('selected_codename', 'sudo install')->and($metadata)->toBeString();
})->with(['missing' => '', 'malformed' => 'noble extra', 'debian' => 'bookworm', 'unknown' => 'jammy']);

it('does not treat package arguments as allowed release codenames', function (): void {
    $transport = new AppDevFakeSshExecutor;

    new RemotePhpPackageManager()->installForAppDev(
        php_package_node(RoleName::AppDev),
        collect(['8.5']),
        php_package_app_dev_ssh($transport),
    );

    foreach ([$transport->commands[0], $transport->commands[1]] as $command) {
        $script = $command->input ?? '';
        $start = mb_strpos($script, str_contains($script, 'expected_id=$1') ? 'expected_id=$1' : 'version=$1');
        $end = mb_strpos($script, 'shift "$allowed_count"') + mb_strlen('shift "$allowed_count"');

        expect($start)->toBeInt()->and($end)->toBeInt();

        $argumentBoundary = substr($script, (int) $start, (int) $end - (int) $start);
        $argumentBoundary .= <<<'BASH'

            selected_codename=''
            for allowed_codename in "${allowed_codenames[@]}"; do
                if [ "bookworm" = "$allowed_codename" ]; then selected_codename=$allowed_codename; fi
            done
            printf '%s\n' "$selected_codename"
            BASH;

        $headerLength = str_contains($script, 'expected_id=$1') ? 3 : 4;
        $arguments = array_slice($command->arguments, 3, $headerLength);
        $arguments[$headerLength - 1] = '1';
        $process = new Process(['bash', '-seu', '--', ...$arguments, 'noble', 'bookworm']);
        $process->setInput($argumentBoundary);
        $process->run();

        expect($process->getExitCode())->toBe(0)->and($process->getOutput())->toBe("\n");
    }
});

it('does not execute a malicious os-release payload in either remote script', function (): void {
    $transport = new AppDevFakeSshExecutor;
    new RemotePhpPackageManager()->installForAppDev(
        php_package_node(RoleName::AppDev),
        collect(['8.5']),
        php_package_app_dev_ssh($transport),
    );

    $root = sys_get_temp_dir().'/orbit-php-os-release-payload-'.bin2hex(random_bytes(6));
    try {
        mkdir($root.'/etc', 0777, true);
        mkdir($root.'/bin', 0777, true);
        $marker = $root.'/executed';
        $osRelease = $root.'/etc/os-release';
        file_put_contents(
            $osRelease,
            "ID=\$(touch ".escapeshellarg($marker).")\nVERSION_CODENAME=\$(touch ".escapeshellarg($marker).")\n",
        );
        file_put_contents(
            $root.'/bin/dpkg-query',
            "#!/usr/bin/env bash\ntouch ".escapeshellarg($root.'/package-mutation')."\nexit 1\n",
        );
        chmod($root.'/bin/dpkg-query', 0755);
        file_put_contents($root.'/bin/dpkg', "#!/usr/bin/env bash\nprintf 'amd64\\n'\n");
        chmod($root.'/bin/dpkg', 0755);

        foreach ([0, 1] as $commandIndex) {
            $script = str_replace('/etc/os-release', $osRelease, $transport->commands[$commandIndex]->input ?? '');
            $arguments = array_slice($transport->commands[$commandIndex]->arguments, 3);
            $process = new Process(['bash', '-seu', '--', ...$arguments], $root, [
                'PATH' => $root.'/bin:'.getenv('PATH'),
            ]);
            $process->setInput($script);
            $process->run();

            expect($process->getExitCode())
                ->not
                ->toBe(0)
                ->and(is_file($marker))
                ->toBeFalse()
                ->and(is_file($root.'/package-mutation'))
                ->toBeFalse();
        }
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('accepts valid release metadata forms in both remote scripts', function (string $osRelease): void {
    $transport = new AppDevFakeSshExecutor;
    new RemotePhpPackageManager()->installForAppDev(
        php_package_node(RoleName::AppDev),
        collect(['8.5']),
        php_package_app_dev_ssh($transport),
    );
    $root = sys_get_temp_dir().'/orbit-php-os-release-quotes-'.bin2hex(random_bytes(6));
    try {
        mkdir($root.'/etc/apt/sources.list.d', 0777, true);
        mkdir($root.'/bin', 0777, true);
        file_put_contents($root.'/etc/os-release', $osRelease);
        file_put_contents($root.'/bin/dpkg-query', "#!/usr/bin/env bash\nexit 1\n");
        chmod($root.'/bin/dpkg-query', 0755);
        file_put_contents($root.'/bin/dpkg', "#!/usr/bin/env bash\nprintf 'amd64\\n'\n");
        chmod($root.'/bin/dpkg', 0755);
        foreach ([0, 1] as $commandIndex) {
            $script = str_replace(
                ['/etc/os-release', '/etc/apt'],
                [$root.'/etc/os-release', $root.'/etc/apt'],
                $transport->commands[$commandIndex]->input ?? '',
            );
            $arguments = array_map(
                static fn (string $argument): string => str_replace(
                    ['/etc/os-release', '/etc/apt'],
                    [$root.'/etc/os-release', $root.'/etc/apt'],
                    $argument,
                ),
                array_slice($transport->commands[$commandIndex]->arguments, 3),
            );
            $process = new Process(['bash', '-seu', '--', ...$arguments], $root, [
                'PATH' => $root.'/bin:'.getenv('PATH'),
            ]);
            $process->setInput($script);
            $process->run();
            expect($process->getErrorOutput())->not->toContain('Orbit requires Ubuntu');
        }
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
})->with([
    'bare values' => ["ID=ubuntu\nVERSION_CODENAME=noble\n"],
    'paired double quotes' => ["ID=\"ubuntu\"\nVERSION_CODENAME=\"noble\"\n"],
    'paired single quotes' => ["ID='ubuntu'\nVERSION_CODENAME='noble'\n"],
    'mixed paired quotes' => ["ID=\"ubuntu\"\nVERSION_CODENAME='noble'\n"],
    'final line without newline' => ["ID=ubuntu\nVERSION_CODENAME=noble"],
]);

it('rejects invalid quoted release metadata in both remote scripts', function (string $id, string $codename): void {
    $transport = new AppDevFakeSshExecutor;
    new RemotePhpPackageManager()->installForAppDev(
        php_package_node(RoleName::AppDev),
        collect(['8.5']),
        php_package_app_dev_ssh($transport),
    );
    $root = sys_get_temp_dir().'/orbit-php-os-release-invalid-quotes-'.bin2hex(random_bytes(6));
    $requirement = 'Orbit requires Ubuntu 24.04 Noble or Ubuntu 26.04 Resolute.';
    try {
        mkdir($root.'/etc/apt/sources.list.d', 0777, true);
        mkdir($root.'/usr/share/keyrings', 0777, true);
        mkdir($root.'/bin', 0777, true);
        file_put_contents($root.'/etc/os-release', "ID={$id}\nVERSION_CODENAME={$codename}\n");
        file_put_contents(
            $root.'/bin/dpkg-query',
            "#!/usr/bin/env bash\ntouch ".escapeshellarg($root.'/package-mutation')."\nexit 1\n",
        );
        file_put_contents(
            $root.'/bin/install',
            "#!/usr/bin/env bash\ntouch ".escapeshellarg($root.'/source-mutation')."\nexit 1\n",
        );
        chmod($root.'/bin/dpkg-query', 0755);
        chmod($root.'/bin/install', 0755);

        foreach ([0, 1] as $commandIndex) {
            $script = str_replace(
                ['/etc/os-release', '/etc/apt', '/usr/share/keyrings'],
                [$root.'/etc/os-release', $root.'/etc/apt', $root.'/usr/share/keyrings'],
                $transport->commands[$commandIndex]->input ?? '',
            );
            $arguments = array_map(
                static fn (string $argument): string => str_replace(
                    ['/etc/os-release', '/etc/apt', '/usr/share/keyrings'],
                    [$root.'/etc/os-release', $root.'/etc/apt', $root.'/usr/share/keyrings'],
                    $argument,
                ),
                array_slice($transport->commands[$commandIndex]->arguments, 3),
            );
            $process = new Process(['bash', '-seu', '--', ...$arguments], $root, [
                'PATH' => $root.'/bin:'.getenv('PATH'),
            ]);
            $process->setInput($script);
            $process->run();

            expect($process->getExitCode())
                ->not
                ->toBe(0)
                ->and(trim($process->getErrorOutput()))
                ->toBe($requirement)
                ->and($process->getOutput())
                ->toBe('')
                ->and(is_file($root.'/package-mutation'))
                ->toBeFalse()
                ->and(is_file($root.'/source-mutation'))
                ->toBeFalse();
        }
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
})->with([
    'unclosed double quote in ID' => ['"ubuntu', 'noble'],
    'unclosed single quote in ID' => ["'ubuntu", 'noble'],
    'mismatched quote in ID' => ['"ubuntu\'', 'noble'],
    'unclosed double quote in VERSION_CODENAME' => ['ubuntu', '"noble'],
    'unclosed single quote in VERSION_CODENAME' => ['ubuntu', "'noble"],
    'mismatched quote in VERSION_CODENAME' => ['ubuntu', '"noble\''],
]);

it('uses exact Laravel package profiles without duplicates', function (string $role, string $version): void {
    $transport = new AppDevFakeSshExecutor;
    $nodeRole = $role === 'app-dev' ? RoleName::AppDev : RoleName::AppProd;
    $node = php_package_node($nodeRole);
    $manager = new RemotePhpPackageManager;

    $install = $role === 'app-dev'
        ? fn () => $manager->installForAppDev($node, collect([$version]), php_package_app_dev_ssh($transport))
        : fn () => $manager->installForAppProd($node, collect([$version]), php_package_app_prod_ssh($transport));
    $install();

    $sourcePackages = array_values(array_filter(
        $transport->commands[0]->arguments,
        static fn (string $argument): bool => str_starts_with($argument, 'php'),
    ));
    $installPackages = array_values(array_filter(
        $transport->commands[1]->arguments,
        static fn (string $argument): bool => str_starts_with($argument, 'php'),
    ));

    expect($sourcePackages)
        ->toBe(php_package_profile($version, $role))
        ->toBe($installPackages)
        ->toHaveCount(count(array_unique($sourcePackages)));
})->with([
    'app-dev PHP 8.4' => ['app-dev', '8.4'],
    'app-dev PHP 8.5' => ['app-dev', '8.5'],
    'app-dev future PHP' => ['app-dev', '8.6'],
    'app-prod PHP 8.4' => ['app-prod', '8.4'],
    'app-prod PHP 8.5' => ['app-prod', '8.5'],
    'app-prod future PHP' => ['app-prod', '8.6'],
]);

it('enables PCOV only for app-dev CLI and verifies both SAPIs', function (): void {
    $transport = new AppDevFakeSshExecutor;

    new RemotePhpPackageManager()->installForAppDev(
        php_package_node(RoleName::AppDev),
        collect(['8.5']),
        php_package_app_dev_ssh($transport),
    );

    $script = $transport->commands[1]->input ?? '';

    expect($script)
        ->toContain(
            'phpenmod -v "$version" -s cli pcov',
            'phpdismod -v "$version" -s fpm pcov',
            'php"$version" -m',
            'php-fpm"$version" -m',
            'systemctl enable --now "php$version-fpm.service"',
            'systemctl is-enabled --quiet "php$version-fpm.service"',
            'systemctl is-active --quiet "php$version-fpm.service"',
        )
        ->not->toContain('xdebug', 'opentelemetry');
});

it('verifies the managed absolute PHP binaries', function (): void {
    $transport = new AppDevFakeSshExecutor;

    new RemotePhpPackageManager()->installForAppDev(
        php_package_node(RoleName::AppDev),
        collect(['8.5']),
        php_package_app_dev_ssh($transport),
    );

    $script = $transport->commands[1]->input ?? '';
    $verification = substr($script, (int) mb_strpos($script, 'sudo systemctl enable --now'));
    $root = sys_get_temp_dir().'/orbit-php-binary-verification-'.bin2hex(random_bytes(6));

    try {
        mkdir($root.'/bin', 0777, true);
        mkdir($root.'/usr/bin', 0777, true);
        mkdir($root.'/usr/sbin', 0777, true);
        file_put_contents(
            $root.'/bin/php8.5',
            "#!/usr/bin/env bash\necho shadowed >> ".escapeshellarg($root.'/calls')."\nexit 1\n",
        );
        file_put_contents(
            $root.'/bin/php-fpm8.5',
            "#!/usr/bin/env bash\necho shadowed >> ".escapeshellarg($root.'/calls')."\nexit 1\n",
        );
        $cliModules = "#!/usr/bin/env bash\nprintf '%s\\n' bcmath curl gd imagick intl mbstring mysqli pdo_mysql pdo_pgsql redis pdo_sqlite simplexml xml zip pcov\n";
        $fpmModules = str_replace(' pcov', '', $cliModules);
        file_put_contents($root.'/usr/bin/php8.5', $cliModules);
        file_put_contents($root.'/usr/sbin/php-fpm8.5', $fpmModules);
        file_put_contents($root.'/bin/systemctl', "#!/usr/bin/env bash\nexit 0\n");
        foreach ([
            $root.'/bin/php8.5',
            $root.'/bin/php-fpm8.5',
            $root.'/usr/bin/php8.5',
            $root.'/usr/sbin/php-fpm8.5',
            $root.'/bin/systemctl',
        ] as $binary) {
            chmod($binary, 0755);
        }
        $verification = str_replace(
            ['sudo systemctl', '/usr/bin', '/usr/sbin'],
            ['systemctl', $root.'/usr/bin', $root.'/usr/sbin'],
            $verification,
        );
        $process = new Process(['bash', '-seu', '--', '8.5', 'app-dev'], $root, [
            'PATH' => $root.'/bin:'.getenv('PATH'),
        ]);
        $process->setInput("version=\$1\n".$verification);
        $process->run();

        expect($process->getExitCode())
            ->toBe(0)
            ->and(is_file($root.'/calls') ? file_get_contents($root.'/calls') : '')
            ->toBe('');
    } finally {
        new Filesystem()->deleteDirectory($root);
    }

    expect($script)
        ->toContain(
            '/usr/bin/php"$version" -v',
            '/usr/sbin/php-fpm"$version" -v',
            '/usr/bin/php"$version" -m',
            '/usr/sbin/php-fpm"$version" -m',
        )
        ->not->toContain(
            "\n                    php\"\$version\" -v",
            "\n                    php-fpm\"\$version\" -v",
            "\n                    cli_modules=\$(php\"\$version\" -m",
            "\n                    fpm_modules=\$(php-fpm\"\$version\" -m",
        );
});

it('does not request or enable PCOV for a pure app-prod node', function (): void {
    $transport = new AppDevFakeSshExecutor;

    new RemotePhpPackageManager()->installForAppProd(
        php_package_node(RoleName::AppProd),
        collect(['8.5']),
        php_package_app_prod_ssh($transport),
    );

    expect(php_package_scripts($transport))
        ->toContain('if printf \'%s\\n\' "$cli_modules" | grep -qxF pcov')
        ->not->toContain(
            'phpenmod -v "$version" -s cli pcov',
            'phpdismod -v "$version" -s fpm pcov',
            'xdebug',
            'opentelemetry',
        )->and(implode(' ', $transport->commands[1]->arguments))
        ->not->toContain('pcov');
});

it('keeps PCOV when app-prod convergence targets a dual-role node', function (): void {
    $transport = new AppDevFakeSshExecutor;
    $node = php_package_node(RoleName::AppProd);
    $node->roles()->create(['role' => RoleName::AppDev]);

    new RemotePhpPackageManager()->installForAppProd(
        $node->load('roles'),
        collect(['8.5']),
        php_package_app_prod_ssh($transport),
    );

    expect(array_slice(array: $transport->commands[1]->arguments, offset: 9))
        ->toContain('php8.5-pcov')
        ->and($transport->commands[1]->input)
        ->toContain('phpenmod -v "$version" -s cli pcov', 'phpdismod -v "$version" -s fpm pcov');
});

it('maps source failures to the stable role-specific contract', function (string $role): void {
    $transport = new AppDevFakeSshExecutor([
        new CommandResult(1, '', 'Orbit requires Ubuntu 26.04 Resolute.', 1, false),
    ]);
    $manager = new RemotePhpPackageManager;
    $nodeRole = $role === 'app-dev' ? RoleName::AppDev : RoleName::AppProd;

    $operation = $role === 'app-dev'
        ? fn () => $manager->installForAppDev(
            php_package_node($nodeRole),
            collect(['8.5']),
            php_package_app_dev_ssh($transport),
        )
        : fn () => $manager->installForAppProd(
            php_package_node($nodeRole),
            collect(['8.5']),
            php_package_app_prod_ssh($transport),
        );

    expect($operation)->toThrow(function (RuntimeConvergenceException $exception) use ($role): void {
        expect($exception->step)
            ->toBe($role === 'app-dev' ? 'php-package-source' : 'app-prod-php-package-source')
            ->and($exception->errorCode)
            ->toBe(
                $role === 'app-dev'
                    ? 'app-dev.php_package_source_unavailable'
                    : 'app-prod.php_package_source_unavailable',
            );
    });

    expect($transport->commands)->toHaveCount(1);
})->with(['app-dev', 'app-prod']);

it('retains valid source state and partial packages when installation fails', function (): void {
    $transport = new AppDevFakeSshExecutor([
        new CommandResult(0, '', '', 1, false),
        new CommandResult(1, '', 'package install failed', 1, false),
    ]);

    expect(fn () => new RemotePhpPackageManager()->installForAppDev(
        php_package_node(RoleName::AppDev),
        collect(['8.5']),
        php_package_app_dev_ssh($transport),
    ))->toThrow(function (RuntimeConvergenceException $exception): void {
        expect($exception->step)
            ->toBe('php-fpm-install')
            ->and($exception->errorCode)
            ->toBe('app-dev.php_install_failed');
    });

    expect($transport->commands)
        ->toHaveCount(2)
        ->and($transport->commands[0]->input)
        ->toContain('trap restore_source EXIT')
        ->and($transport->commands[1]->input)
        ->not->toContain('apt-get remove', 'rm -f /usr/share/keyrings/orbit-sury-php.gpg');
});

it('performs no remote work for an empty version collection', function (): void {
    $transport = new AppDevFakeSshExecutor;

    new RemotePhpPackageManager()->installForAppDev(
        php_package_node(RoleName::AppDev),
        collect(),
        php_package_app_dev_ssh($transport),
    );

    expect($transport->commands)->toBeEmpty();
});

it('renders syntactically valid fixed shell programs', function (): void {
    $transport = new AppDevFakeSshExecutor;

    new RemotePhpPackageManager()->installForAppDev(
        php_package_node(RoleName::AppDev),
        collect(['8.4', '8.5']),
        php_package_app_dev_ssh($transport),
    );

    foreach ($transport->commands as $command) {
        $process = new Process(['bash', '-n']);
        $process->setInput($command->input ?? '');
        $process->run();

        expect($process->isSuccessful())
            ->toBeTrue(
                $process->getErrorOutput()."\n".($command->input ?? ''),
            );
    }
});

/** @mago-expect lint:excessive-parameter-list The dataset keeps release selection and candidate-origin rejection in one executable shell scenario. */
it('executes the source program with the selected Ubuntu suite', function (
    string $id,
    string $codename,
    bool $valid,
    ?string $origin = null,
    ?string $foreignOrigin = null,
    string $originMetadata = '',
    string $architecture = 'amd64',
): void {
    $transport = new AppDevFakeSshExecutor;
    new RemotePhpPackageManager()->installForAppDev(
        php_package_node(RoleName::AppDev),
        collect(['8.5']),
        php_package_app_dev_ssh($transport),
    );

    $root = sys_get_temp_dir().'/orbit-php-source-'.bin2hex(random_bytes(6));
    try {
        mkdir($root.'/bin', 0777, true);
        mkdir($root.'/etc/apt/sources.list.d', 0777, true);
        mkdir($root.'/usr/share/keyrings', 0777, true);
        file_put_contents($root.'/etc/os-release', "ID={$id}\nVERSION_CODENAME={$codename}\n");
        $script = str_replace(
            ['/etc/os-release', '/etc/apt', '/usr/share/keyrings'],
            [$root.'/etc/os-release', $root.'/etc/apt', $root.'/usr/share/keyrings'],
            $transport->commands[0]->input ?? '',
        );
        $arguments = array_map(
            static fn (string $argument): string => str_replace(
                ['/etc/os-release', '/etc/apt', '/usr/share/keyrings'],
                [$root.'/etc/os-release', $root.'/etc/apt', $root.'/usr/share/keyrings'],
                $argument,
            ),
            array_slice($transport->commands[0]->arguments, 3),
        );
        php_package_write_source_binaries($root, $origin ?? $codename, $foreignOrigin, $originMetadata, $architecture);
        $process = new Process(['bash', '-seu', '--', ...$arguments], $root, ['PATH' => $root.'/bin:'.getenv('PATH')]);
        $process->setInput($script);
        $process->run();
        if (! $valid) {
            expect($process->getExitCode())->not->toBe(0);

            return;
        }
        if ($process->getExitCode() !== 0) {
            throw new RuntimeException($process->getErrorOutput()."\n".$process->getOutput());
        }
        expect($process->getExitCode())
            ->toBe(0)
            ->and(file_get_contents($root.'/etc/apt/sources.list.d/orbit-php.sources'))
            ->toContain("Suites: {$codename}");
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
})->with([
    'noble' => ['ubuntu', 'noble', true],
    'resolute' => ['ubuntu', 'resolute', true],
    'missing' => ['ubuntu', '', false],
    'malformed' => ['ubuntu', 'noble extra', false],
    'debian' => ['debian', 'bookworm', false],
    'jammy' => ['ubuntu', 'jammy', false],
    'mismatched origin' => ['ubuntu', 'noble', false, 'resolute'],
    'foreign origin for candidate' => ['ubuntu', 'noble', false, null, 'archive.ubuntu.com/ubuntu'],
    'extra origin metadata' => ['ubuntu', 'noble', false, null, null, ' extra'],
    'wrong candidate architecture' => ['ubuntu', 'noble', false, null, null, '', 'arm64'],
]);

it('accepts an installed-only policy candidate when Sury publishes another version', function (): void {
    $transport = new AppDevFakeSshExecutor;
    new RemotePhpPackageManager()->installForAppDev(
        php_package_node(RoleName::AppDev),
        collect(['8.5']),
        php_package_app_dev_ssh($transport),
    );
    $root = sys_get_temp_dir().'/orbit-php-installed-candidate-'.bin2hex(random_bytes(6));
    try {
        mkdir($root.'/bin', 0777, true);
        mkdir($root.'/etc/apt/sources.list.d', 0777, true);
        mkdir($root.'/usr/share/keyrings', 0777, true);
        file_put_contents($root.'/etc/os-release', "ID=ubuntu\nVERSION_CODENAME=noble\n");
        $script = str_replace(
            ['/etc/os-release', '/etc/apt', '/usr/share/keyrings'],
            [$root.'/etc/os-release', $root.'/etc/apt', $root.'/usr/share/keyrings'],
            $transport->commands[0]->input ?? '',
        );
        $arguments = array_map(
            static fn (string $argument): string => str_replace(
                ['/etc/os-release', '/etc/apt', '/usr/share/keyrings'],
                [$root.'/etc/os-release', $root.'/etc/apt', $root.'/usr/share/keyrings'],
                $argument,
            ),
            array_slice($transport->commands[0]->arguments, 3),
        );
        php_package_write_source_binaries($root, 'noble', null);
        file_put_contents(
            $root.'/bin/dpkg-query',
            "#!/usr/bin/env bash\nprintf '%s\\n' 'install ok installed 6.3.0-2+ubuntu24.04.1+deb.sury.org+1'\n",
        );
        chmod($root.'/bin/dpkg-query', 0755);
        file_put_contents($root.'/bin/apt-cache', <<<'BASH'
            #!/usr/bin/env bash
            package="${@: -1}"
            case "$1" in
             policy) printf 'Candidate: 6.3.0-2+ubuntu24.04.1+deb.sury.org+1\n';;
             madison) printf '%s | 6.3.0-2+0~20260711.1+ubuntu24.04~1.gbp123 | https://packages.sury.org/php noble/main amd64 Packages\n' "$package";;
            esac
            BASH);
        chmod($root.'/bin/apt-cache', 0755);
        $process = new Process(['bash', '-seu', '--', ...$arguments], $root, ['PATH' => $root.'/bin:'.getenv('PATH')]);
        $process->setInput($script);
        $process->run();
        expect($process->getExitCode())->toBe(0);
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('rejects unsafe installed-only candidate fallback metadata', function (string $madison): void {
    $transport = new AppDevFakeSshExecutor;
    new RemotePhpPackageManager()->installForAppDev(
        php_package_node(RoleName::AppDev),
        collect(['8.5']),
        php_package_app_dev_ssh($transport),
    );
    $root = sys_get_temp_dir().'/orbit-php-installed-negative-'.bin2hex(random_bytes(6));
    try {
        mkdir($root.'/bin', 0777, true);
        mkdir($root.'/etc/apt/sources.list.d', 0777, true);
        mkdir($root.'/usr/share/keyrings', 0777, true);
        file_put_contents($root.'/etc/os-release', "ID=ubuntu\nVERSION_CODENAME=noble\n");
        $script = str_replace(
            ['/etc/os-release', '/etc/apt', '/usr/share/keyrings'],
            [$root.'/etc/os-release', $root.'/etc/apt', $root.'/usr/share/keyrings'],
            $transport->commands[0]->input ?? '',
        );
        $arguments = array_map(
            static fn (string $argument): string => str_replace(
                ['/etc/os-release', '/etc/apt', '/usr/share/keyrings'],
                [$root.'/etc/os-release', $root.'/etc/apt', $root.'/usr/share/keyrings'],
                $argument,
            ),
            array_slice($transport->commands[0]->arguments, 3),
        );
        php_package_write_source_binaries($root, 'noble', null);
        file_put_contents(
            $root.'/bin/dpkg-query',
            "#!/usr/bin/env bash\nprintf '%s\\n' 'install ok installed 6.3.0-2+ubuntu24.04.1+deb.sury.org+1'\n",
        );
        chmod($root.'/bin/dpkg-query', 0755);
        file_put_contents(
            $root.'/bin/apt-cache',
            "#!/usr/bin/env bash\npackage=\"\${@: -1}\"\ncase \"\$1\" in policy) printf 'Candidate: 6.3.0-2+ubuntu24.04.1+deb.sury.org+1\\n';; madison) printf '%s\\n' "
            .escapeshellarg(str_replace('php8.5-cli', '{package}', $madison))
            .' | sed "s/{package}/$package/g";; esac'
            ."\n",
        );
        chmod($root.'/bin/apt-cache', 0755);
        $process = new Process(['bash', '-seu', '--', ...$arguments], $root, ['PATH' => $root.'/bin:'.getenv('PATH')]);
        $process->setInput($script);
        $process->run();
        expect($process->getExitCode())->not->toBe(0);
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
})->with([
    'foreign candidate' => 'php8.5-cli | 6.3.0-2+ubuntu24.04.1+deb.sury.org+1 | https://mirror.example noble/main amd64 Packages',
    'extra pipe' => 'php8.5-cli | 6.3.0-2+0~20260711 | https://packages.sury.org/php noble/main amd64 Packages | extra',
    'no Sury row' => 'php8.5-cli | 6.3.0-2+0~20260711 | https://mirror.example noble/main amd64 Packages',
    'wrong architecture' => 'php8.5-cli | 6.3.0-2+0~20260711 | https://packages.sury.org/php noble/main arm64 Packages',
    'malformed architecture' => 'php8.5-cli | 6.3.0-2+0~20260711 | https://packages.sury.org/php noble/main amd64:evil Packages',
    'unexpected package' => 'unexpected-package | 6.3.0-2+0~20260711 | https://packages.sury.org/php noble/main amd64 Packages',
]);

it('rejects lookalike origin hostnames', function (): void {
    $transport = new AppDevFakeSshExecutor;
    new RemotePhpPackageManager()->installForAppDev(
        php_package_node(RoleName::AppDev),
        collect(['8.5']),
        php_package_app_dev_ssh($transport),
    );
    $root = sys_get_temp_dir().'/orbit-php-origin-lookalike-'.bin2hex(random_bytes(6));
    try {
        mkdir($root.'/bin', 0777, true);
        mkdir($root.'/etc/apt/sources.list.d', 0777, true);
        mkdir($root.'/usr/share/keyrings', 0777, true);
        file_put_contents($root.'/etc/os-release', "ID=ubuntu\nVERSION_CODENAME=noble\n");
        $script = str_replace(
            ['/etc/os-release', '/etc/apt', '/usr/share/keyrings'],
            [$root.'/etc/os-release', $root.'/etc/apt', $root.'/usr/share/keyrings'],
            $transport->commands[0]->input ?? '',
        );
        $arguments = array_map(
            static fn (string $argument): string => str_replace(
                ['/etc/os-release', '/etc/apt', '/usr/share/keyrings'],
                [$root.'/etc/os-release', $root.'/etc/apt', $root.'/usr/share/keyrings'],
                $argument,
            ),
            array_slice($transport->commands[0]->arguments, 3),
        );
        php_package_write_source_binaries($root, 'noble', null);
        $cache = file_get_contents($root.'/bin/apt-cache');
        file_put_contents($root.'/bin/apt-cache', str_replace(
            'packages.sury.org/php noble/main',
            'packagesXsuryXorg/php noble/main',
            $cache,
        ));
        $process = new Process(['bash', '-seu', '--', ...$arguments], $root, ['PATH' => $root.'/bin:'.getenv('PATH')]);
        $process->setInput($script);
        $process->run();
        expect($process->getExitCode())->not->toBe(0);
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('removes root-owned candidates and restores managed state when publication fails', function (): void {
    $transport = new AppDevFakeSshExecutor;
    new RemotePhpPackageManager()->installForAppDev(
        php_package_node(RoleName::AppDev),
        collect(['8.5']),
        php_package_app_dev_ssh($transport),
    );

    $root = sys_get_temp_dir().'/orbit-php-rollback-'.bin2hex(random_bytes(6));
    try {
        mkdir($root.'/bin', 0777, true);
        mkdir($root.'/etc/apt/sources.list.d', 0777, true);
        mkdir($root.'/usr/share/keyrings', 0777, true);
        file_put_contents($root.'/etc/os-release', "ID=ubuntu\nVERSION_CODENAME=noble\n");
        file_put_contents($root.'/usr/share/keyrings/orbit-sury-php.gpg', 'old-key');
        file_put_contents($root.'/etc/apt/sources.list.d/orbit-php.sources', 'old-source');
        $script = str_replace(
            ['/etc/os-release', '/etc/apt', '/usr/share/keyrings'],
            [$root.'/etc/os-release', $root.'/etc/apt', $root.'/usr/share/keyrings'],
            $transport->commands[0]->input ?? '',
        );
        $arguments = array_map(
            static fn (string $argument): string => str_replace(
                ['/etc/os-release', '/etc/apt', '/usr/share/keyrings'],
                [$root.'/etc/os-release', $root.'/etc/apt', $root.'/usr/share/keyrings'],
                $argument,
            ),
            array_slice($transport->commands[0]->arguments, 3),
        );
        php_package_write_source_binaries($root, 'noble', null);
        file_put_contents($root.'/bin/install', <<<'BASH'
            #!/usr/bin/env bash
            case "${@: -1}" in
                *.orbit.*) : > "${@: -1}"; exit 42 ;;
            esac
            exec /usr/bin/install "$@"
            BASH);
        chmod($root.'/bin/install', 0755);
        $process = new Process(['bash', '-seu', '--', ...$arguments], $root, ['PATH' => $root.'/bin:'.getenv('PATH')]);
        $process->setInput($script);
        $process->run();

        expect($process->getExitCode())
            ->not
            ->toBe(0)
            ->and(file_get_contents($root.'/usr/share/keyrings/orbit-sury-php.gpg'))
            ->toBe('old-key')
            ->and(file_get_contents($root.'/etc/apt/sources.list.d/orbit-php.sources'))
            ->toBe('old-source')
            ->and(glob($root.'/usr/share/keyrings/*.orbit.*'))
            ->toBe([])
            ->and(glob($root.'/etc/apt/sources.list.d/*.orbit.*'))
            ->toBe([]);
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('restores key state when source publication mv fails', function (): void {
    $transport = new AppDevFakeSshExecutor;
    new RemotePhpPackageManager()->installForAppDev(
        php_package_node(RoleName::AppDev),
        collect(['8.5']),
        php_package_app_dev_ssh($transport),
    );
    $root = sys_get_temp_dir().'/orbit-php-source-mv-'.bin2hex(random_bytes(6));
    try {
        mkdir($root.'/bin', 0777, true);
        mkdir($root.'/etc/apt/sources.list.d', 0777, true);
        mkdir($root.'/usr/share/keyrings', 0777, true);
        file_put_contents($root.'/etc/os-release', "ID=ubuntu\nVERSION_CODENAME=noble\n");
        file_put_contents($root.'/usr/share/keyrings/orbit-sury-php.gpg', 'old-key');
        file_put_contents($root.'/etc/apt/sources.list.d/orbit-php.sources', 'old-source');
        $script = str_replace(
            ['/etc/os-release', '/etc/apt', '/usr/share/keyrings'],
            [$root.'/etc/os-release', $root.'/etc/apt', $root.'/usr/share/keyrings'],
            $transport->commands[0]->input ?? '',
        );
        $arguments = array_map(
            static fn (string $argument): string => str_replace(
                ['/etc/os-release', '/etc/apt', '/usr/share/keyrings'],
                [$root.'/etc/os-release', $root.'/etc/apt', $root.'/usr/share/keyrings'],
                $argument,
            ),
            array_slice($transport->commands[0]->arguments, 3),
        );
        php_package_write_source_binaries($root, 'noble', null);
        file_put_contents($root.'/bin/mv', <<<'BASH'
            #!/usr/bin/env bash
            case "$2" in *orbit-php.sources) exit 42;; esac
            exec /usr/bin/mv "$@"
            BASH);
        chmod($root.'/bin/mv', 0755);
        $process = new Process(['bash', '-seu', '--', ...$arguments], $root, ['PATH' => $root.'/bin:'.getenv('PATH')]);
        $process->setInput($script);
        $process->run();
        expect($process->getExitCode())
            ->not
            ->toBe(0)
            ->and(file_get_contents($root.'/usr/share/keyrings/orbit-sury-php.gpg'))
            ->toBe('old-key')
            ->and(file_get_contents($root.'/etc/apt/sources.list.d/orbit-php.sources'))
            ->toBe('old-source')
            ->and(glob($root.'/usr/share/keyrings/*.orbit.*'))
            ->toBe([])
            ->and(glob($root.'/etc/apt/sources.list.d/*.orbit.*'))
            ->toBe([]);
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

function php_package_write_source_binaries(
    string $root,
    string $origin,
    ?string $foreignOrigin,
    string $originMetadata = '',
    string $architecture = 'amd64',
): void {
    $write = static function (string $name, string $body) use ($root): void {
        file_put_contents($root.'/bin/'.$name, "#!/usr/bin/env bash\n{$body}\n");
        chmod($root.'/bin/'.$name, 0755);
    };

    $write('sudo', 'exec "$@"');
    $write(
        'install',
        'args=(); skip=0; for arg in "$@"; do if [ "$skip" = 1 ]; then skip=0; continue; fi; case "$arg" in -o|-g) skip=1;; *) args+=("$arg");; esac; done; exec /usr/bin/install "${args[@]}"',
    );
    $write(
        'curl',
        'out=""; while [ "$#" -gt 0 ]; do [ "$1" = --output ] && { out="$2"; shift 2; continue; }; shift; done; : > "$out"',
    );
    $write('sha256sum', 'exit 0');
    $write(
        'gpg',
        'printf "%s\\nfpr:::::::::%s:\\nfpr:::::::::%s:\\n" x 15058500A0235D97F5D10063B188E2B695BD4743 45BEA3E529112086C622F8A4B214EAC28059B8AC',
    );
    $write('mktemp', 'exec /usr/bin/mktemp "$@"');
    $write('apt-get', 'exit 0');
    $write('dpkg', 'if [ "$1" = --print-architecture ]; then printf "amd64\\n"; fi');
    $write(
        'apt-cache',
        'package="${@: -1}"; case "$1" in policy) printf "Candidate: 8.5.0\\n";; madison) printf "%s | 8.5.0 | https://packages.sury.org/php '
        .$origin
        .'/main '
        .$architecture
        .' Packages'
        .$originMetadata
        .'\\n" "$package"'
        .(
            $foreignOrigin === null
                ? ''
                : '; printf "%s | 8.5.0 | https://'.$foreignOrigin.'/main '.$architecture.' Packages\\n" "$package"'
        )
        .';; esac',
    );
    $write('awk', 'exec /usr/bin/awk "$@"');
    $write('grep', 'exec /usr/bin/grep "$@"');
}

function php_package_node(RoleName $role): Node
{
    $count = Node::query()->count();
    $node = Node::query()->create([
        'name' => 'php-package-node-'.str_replace(search: '-', replace: '', subject: $role->value)."-{$count}",
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.44',
        'wireguard_address' => '10.44.0.'.(44 + $count),
        'user' => 'orbit',
    ]);
    $node->roles()->create(['role' => $role]);

    return $node->load('roles');
}

function php_package_app_dev_ssh(AppDevFakeSshExecutor $transport): AppDevSshExecutor
{
    return new AppDevSshExecutor(
        ssh: $transport,
        keys: php_package_keys(),
        knownHosts: php_package_known_hosts(),
    );
}

function php_package_app_prod_ssh(AppDevFakeSshExecutor $transport): AppProdSshExecutor
{
    return new AppProdSshExecutor(
        ssh: $transport,
        keys: php_package_keys(),
        knownHosts: php_package_known_hosts(),
    );
}

function php_package_keys(): SshKeyProvider
{
    return new class implements SshKeyProvider {
        public function privateKeyPath(): string
        {
            return '/tmp/orbit-test-key';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 TEST';
        }
    };
}

function php_package_known_hosts(): KnownHostsStore
{
    return new class implements KnownHostsStore {
        public function path(): string
        {
            return '/tmp/orbit-test-known-hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
}

/** @return list<string> */
function php_package_profile(string $version, string $profile): array
{
    $suffixes = [
        'cli',
        'fpm',
        'common',
        'bcmath',
        'curl',
        'gd',
        'imagick',
        'intl',
        'mbstring',
        'mysql',
        'pgsql',
        'redis',
        'sqlite3',
        'xml',
        'zip',
    ];

    if ($version === '8.4') {
        $suffixes[] = 'opcache';
    }

    if ($profile === 'app-dev') {
        $suffixes[] = 'pcov';
    }

    return array_map(static fn (string $suffix): string => "php{$version}-{$suffix}", $suffixes);
}

function php_package_scripts(AppDevFakeSshExecutor $transport): string
{
    return Collection::make($transport->commands)
        ->map(static fn (RemoteCommand $command): string => $command->input ?? '')
        ->implode("\n");
}
