<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;

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

it('keeps the workflow artifact names, dest paths, and hosts aligned with the builder', function (): void {
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
        ->and($workflow)->toContain('GITHUB_TOKEN: ${{ github.token }}')
        ->and($workflow)->toContain('runs-on: ubuntu-latest')
        ->and($workflow)->toContain('runs-on: [self-hosted, macOS, ARM64, mini]')
        ->and($workflow)->toContain("vars.ORBIT_MINI_RUNNER == 'true'")
        ->and($workflow)->not->toContain('macos-latest')
        ->and($workflow)->not->toContain('macos-14')
        ->and($workflow)->not->toContain('macos-15')
        ->and($builder)->toContain('--dest=./builds/dist')
        ->and($builder)->toContain('packages/php-sdk')
        ->and($builder)->toContain('vendor/nckrtl/orbit-php-sdk')
        ->and($builder)->toContain('phpacker/vendor/bin/phpacker')
        ->and($builder)->toContain('variables_order=EGPCS')
        ->and($builder)->toContain('php-bin')
        ->and($builder)->not->toContain('packages/core')
        ->and($builder)->not->toContain('compressFiles')
        ->and(cli_binary_repo_root().'/apps/cli/box.json')->toBeFile()
        ->and((string) file_get_contents(cli_binary_repo_root().'/apps/cli/box.json'))->toContain('"compression": "GZ"');
});

it('keeps Laravel HTTP client in the packed production install', function (): void {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    $lock = json_decode((string) file_get_contents(base_path('composer.lock')), true, flags: JSON_THROW_ON_ERROR);
    $provider = (string) file_get_contents(base_path('app/Providers/AppServiceProvider.php'));
    $productionPackages = array_column($lock['packages'] ?? [], 'name');

    expect($composer['require']['illuminate/http'] ?? null)->toBe('^13.0')
        ->and($productionPackages)->toContain('illuminate/http')
        ->and($provider)->toContain('Illuminate\\Http\\Client\\Factory')
        ->and($provider)->toContain('HttpFactory::class');
});

it('binds the Laravel HTTP client factory as a singleton', function (): void {
    $first = app(Factory::class);
    $second = app(Factory::class);

    expect($first)->toBeInstanceOf(Factory::class)
        ->and($first)->toBe($second);
});

it('prints a git describe version', function (): void {
    [$exitCode, $output] = run_cli_binary_script(cli_binary_repo_root().'/bin/orbit-version', []);

    expect($exitCode)->toBe(0)
        ->and(trim($output))->not->toBe('');
});
