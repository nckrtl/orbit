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
            '--no-remove --no-upgrade',
            'package_version=$(dpkg-query',
            'apt-cache madison "$package"',
            "'$2 == version && $3 == source { found = 1 } END { exit !found }'",
            '[[ "$package_version" =~ \\+0~[0-9]{8}\\.[0-9]+\\+ubuntu',
        )
        ->not->toContain('make install', './configure', 'docker build', 'static-php-cli');
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
