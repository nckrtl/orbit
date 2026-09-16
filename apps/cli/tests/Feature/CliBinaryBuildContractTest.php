<?php

declare(strict_types=1);

function cli_binary_repo_root(): string
{
    return dirname(base_path(), 2);
}

function cli_binary_builder_script(): string
{
    return cli_binary_repo_root().'/bin/orbit-build-cli-binary';
}

/**
 * @param  list<string>  $arguments
 * @return array{int, string}
 */
function run_cli_binary_script(string $script, array $arguments): array
{
    $command = array_merge([$script], $arguments);
    $process = proc_open(
        $command,
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        cli_binary_repo_root(),
    );

    expect($process)->not->toBeFalse();

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [$exitCode, $stdout.$stderr];
}

it('prints the artifact contract for --help', function (): void {
    [$exitCode, $output] = run_cli_binary_script(cli_binary_builder_script(), ['--help']);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('mac arm')
        ->and($output)->toContain('linux x64')
        ->and($output)->toContain('apps/cli/builds/dist/mac/mac-arm')
        ->and($output)->toContain('apps/cli/builds/dist/linux/linux-x64')
        ->and($output)->toContain('orbit-macos-arm64')
        ->and($output)->toContain('orbit-linux-x64');
});

it('exits 2 when a target is missing', function (): void {
    [$exitCode, $output] = run_cli_binary_script(cli_binary_builder_script(), []);

    expect($exitCode)->toBe(2)
        ->and($output)->toContain('Usage:');
});

it('refuses an unsupported target', function (): void {
    [$exitCode, $output] = run_cli_binary_script(cli_binary_builder_script(), ['windows', 'x64']);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('unsupported target: windows x64');
});

it('keeps the workflow artifact names and dest paths aligned with the builder', function (): void {
    $workflow = file_get_contents(cli_binary_repo_root().'/.github/workflows/orbit-cli-binary.yml');
    $builder = file_get_contents(cli_binary_builder_script());

    expect($workflow)->toBeString()
        ->and($builder)->toBeString()
        ->and($workflow)->toContain('name: orbit-macos-arm64')
        ->and($workflow)->toContain('name: orbit-linux-x64')
        ->and($workflow)->toContain('path: apps/cli/builds/dist/mac/mac-arm')
        ->and($workflow)->toContain('path: apps/cli/builds/dist/linux/linux-x64')
        ->and($workflow)->toContain('bin/orbit-build-cli-binary mac arm')
        ->and($workflow)->toContain('bin/orbit-build-cli-binary linux x64')
        ->and($workflow)->toContain('working-dir=apps/cli/phpacker')
        ->and($builder)->toContain('--dest=./builds/dist')
        ->and($builder)->toContain('packages/php-sdk')
        ->and($builder)->toContain('vendor/nckrtl/orbit-php-sdk')
        ->and($builder)->toContain('phpacker/vendor/bin/phpacker')
        ->and($builder)->not->toContain('packages/core')
        ->and($builder)->not->toContain('compressFiles')
        ->and(cli_binary_repo_root().'/apps/cli/box.json')->toBeFile()
        ->and((string) file_get_contents(cli_binary_repo_root().'/apps/cli/box.json'))->toContain('"compression": "GZ"');
});

it('prints a git describe version', function (): void {
    [$exitCode, $output] = run_cli_binary_script(cli_binary_repo_root().'/bin/orbit-version', []);

    expect($exitCode)->toBe(0)
        ->and(trim($output))->not->toBe('');
});
