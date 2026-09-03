<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

function observePhpScript(): string
{
    $script = file_get_contents(dirname(__DIR__, 3).'/resources/guest/observe-php.sh');
    if (! is_string($script)) {
        throw new RuntimeException('The observed PHP guest script is unavailable.');
    }

    return $script;
}

it('uses only pinned Sury PHP and packaged PCOV', function (): void {
    $script = observePhpScript();

    expect($script)
        ->toContain(
            'https://packages.sury.org/php/',
            'b486fd5488185c4c46467960fa69c53d5085fec492cf76b9eaf3db33561c9d7c',
            'php8.5-cli',
            'php8.5-fpm',
            'php8.5-pcov',
            'if [[ "$mode" == pcov && "$needs_upgrade" -eq 0 ]]',
            'install_options+=(--no-upgrade)',
            'package_version=$(dpkg-query',
            'apt-cache madison "$package"',
            "-F' [|] '",
            "'$2 == version && $3 == source { found = 1 } END { exit !found }'",
            'chmod 0644 "$source_file"',
        )
        ->not->toContain('"$package_version" =~')
        ->not->toContain('make install', './configure', 'docker build', 'static-php-cli');
});

it('attributes the installed package version to the configured Sury index', function (
    string $madison,
    int $expectedExitCode,
): void {
    $directory = temporaryPath('orbit-sury-attribution-', 4);
    mkdir($directory, 0700, true);
    $aptCache = $directory.'/apt-cache';
    file_put_contents($aptCache, sprintf(<<<'BASH'
        #!/usr/bin/env bash
        set -euo pipefail
        [[ "${1-}" == madison ]]
        printf '%%s\n' %s
        BASH, escapeshellarg($madison)));
    chmod($aptCache, 0700);
    $version = '8.5.10-1+0~20260828.25+ubuntu26.04~1.gbpfea0b8';
    $source = 'https://packages.sury.org/php resolute/main amd64 Packages';
    $process = new Process(
        [
            'bash',
            '-c',
            'source "$1"; is_sury_package "$2" "$3" "$4"',
            'verify-sury-package',
            dirname(__DIR__, 3).'/resources/guest/observe-php.sh',
            'php8.5-cli',
            $version,
            $source,
        ],
        env: [
            'PATH' => $directory.':'.getenv('PATH'),
        ],
    );

    $exitCode = $process->run();

    expect($exitCode)->toBe($expectedExitCode, $process->getErrorOutput().$process->getOutput());
})->with([
    'configured signed source' => [
        'php8.5-cli | 8.5.10-1+0~20260828.25+ubuntu26.04~1.gbpfea0b8'
            .' | https://packages.sury.org/php resolute/main amd64 Packages',
        0,
    ],
    'suffix without configured source attribution' => [
        'php8.5-cli | 8.5.10-1+0~20260828.25+ubuntu26.04~1.gbpfea0b8'
            .' | https://mirror.invalid/php resolute/main amd64 Packages',
        1,
    ],
]);

it('upgrades normal runtime packages and reports exact CLI, FPM, PCOV, and package versions', function (): void {
    $script = observePhpScript();

    expect($script)
        ->toContain(
            'runtime-info) [[ $# -eq 2 ]]; runtime_info "$2"',
            "run(['/usr/bin/php8.5', '-r', 'echo PHP_VERSION;'])",
            "run(['/usr/sbin/php-fpm8.5', '-i'])",
            "run(['dpkg-query', '-W', '-f=\${Version}', '--', package])",
            "'package_versions': package_versions",
        )
        ->not->toContain('[[ "$needs_upgrade" -eq 1 ]] || install_options+=(--no-upgrade)');
});

it('keeps Sury FPM compatible with Orbit privileged convergence', function (): void {
    $script = observePhpScript();

    expect($script)
        ->toContain(
            'orbit-e2e-sury.conf',
            "[Service]\\nProtectSystem=false\\n",
            'systemctl daemon-reload',
        );
});

it('removes only the known base-image PHP collision and verifies the packaged interpreter', function (): void {
    $script = observePhpScript();

    expect($script)
        ->toContain(
            '[[ "$(readlink -- /usr/local/bin/php)" == /opt/orbit/php/8.5/bin/php ]]',
            'Refusing unexpected /usr/local/bin/php runtime collision.',
            '[[ "$(readlink -f /usr/bin/php)" == /usr/bin/php8.5 ]]',
            "command -v php')\" == /usr/bin/php ]]",
        );
});

it('separates phases, preserves concurrent results, and fails incomplete aggregation', function (): void {
    $script = observePhpScript();

    expect($script)
        ->toContain(
            "fopen(\$path, 'x')",
            "'.start.json'",
            "'.result.json'",
            "'schema' => 2",
            'pcov\\start();',
            'pcov\\stop();',
            'pcov\\clear();',
            'starts.keys() != results.keys()',
            'missing or incomplete PCOV process output',
            'stale PCOV process output',
            'malformed PCOV process output',
        );
});

it('enables observation after the disabled default and disables FPM before cleanup returns', function (): void {
    $script = observePhpScript();

    expect($script)
        ->toContain(
            '; priority=98',
            '; priority=99',
            'pcov.enabled=0',
            'pcov.enabled=1',
            'pcov.exclude="~(?:^|/)(?:vendor|tests?|storage/framework|bootstrap/cache)(?:/|$)~"',
            '--resolve gateway.orbit:443:10.44.0.1',
            'phpdismod -v "$version" -s fpm orbit-e2e-observe pcov',
            '!filter_var(ini_get("pcov.enabled"), FILTER_VALIDATE_BOOL)',
            '^pcov support => Enabled$',
        )
        ->not->toContain('phpenmod -v "$version" -s cli -p', 'phpenmod -v "$version" -s fpm -p');
});

it('is valid Bash', function (): void {
    $path = dirname(__DIR__, 3).'/resources/guest/observe-php.sh';

    expect(new Process(['bash', '-n', $path], timeout: 5)->run())->toBe(0);
});
