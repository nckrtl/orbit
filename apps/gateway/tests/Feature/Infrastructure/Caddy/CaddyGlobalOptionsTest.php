<?php

declare(strict_types=1);

use App\Infrastructure\Caddy\CaddyGlobalOptions;
use App\Infrastructure\Metrics\ServiceMetricsConfigRenderer;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

describe('carried global options guard', function (): void {
    it('refuses a carried fragment that opens its own global options block', function (string $fragment, string $options): void {
        foreach (caddy_global_options_awks() as $awk) {
            $result = caddy_global_options_guard(['00-unmanaged.caddy' => $fragment, 'app-dev.caddy' => "app.test {\n}\n"], awk: $awk);

            expect($result['exit'])
                ->toBe(1, $awk)
                ->and($result['stderr'])
                ->toBe("Caddy fragment 00-unmanaged.caddy opens its own global options block ({$options}). Orbit writes the only global options block. Remove that block from {$result['source_main']}, then publish again.\n", $awk);
        }
    })->with([
        'one option' => ["{\n    local_certs\n}\n", 'local_certs'],
        'comments, blank lines, and nested blocks' => [
            "# Operator settings\n\n{\n    email ops@example.test\n    servers {\n        protocols h1 h2\n    }\n    # debug\n    auto_https off\n}\n\nexample.test {\n    respond ok\n}\n",
            'email, servers, auto_https',
        ],
        'an empty block' => ["{\n}\n", 'empty'],
        'CRLF line endings after a blank line' => ["\r\n{\r\n    local_certs\r\n}\r\n", 'local_certs'],
        'a byte order mark' => ["\u{FEFF}{\n    local_certs\n}\n", 'local_certs'],
        'trailing comments' => ["{ # operator\n    servers { # tuning\n        protocols h1\n    }\n    email ops@example.test # contact\n}\n", 'servers, email'],
        'a one-line block' => ["{ local_certs }\n", 'local_certs'],
    ]);

    it('accepts carried fragments that do not open a global options block', function (string $fragment): void {
        foreach (caddy_global_options_awks() as $awk) {
            $result = caddy_global_options_guard(['00-unmanaged.caddy' => $fragment], awk: $awk);

            expect($result['exit'])->toBe(0, $awk)->and($result['stderr'])->toBe('', $awk);
        }
    })->with([
        'a site block' => "example.test {\n    respond ok\n}\n",
        'a snippet' => "(common) {\n    encode gzip\n}\n",
        'an environment placeholder address' => "{\$SITE_ADDRESS} {\n    respond ok\n}\n",
        'comments only' => "# nothing here\n",
        'an empty file' => '',
        'a CRLF site block' => "example.test {\r\n    respond ok\r\n}\r\n",
        'a site block after a trailing-comment line' => "example.test { # site\n    respond \"a # b\"\n}\n",
        'the Orbit service metrics fragment' => new ServiceMetricsConfigRenderer()->caddy('10.44.0.4', '10.44.0.7'),
    ]);

    it('names the fragment in the published version when Orbit already carries it', function (): void {
        $result = caddy_global_options_guard(
            fragments: ['custom.caddy' => "{\n    local_certs\n}\n"],
            publishedFragments: ['custom.caddy' => "{\n    local_certs\n}\n", 'Caddyfile' => ''],
        );

        expect($result['exit'])
            ->toBe(1)
            ->and($result['stderr'])
            ->toContain('Remove that block from '.dirname($result['source_main']).'/fragments/custom.caddy, then publish again.');
    });
});

/**
 * @param  array<string, string>  $fragments
 * @param  array<string, string>|null  $publishedFragments
 * @param  string  $awk  The awk that `awk` resolves to while the guard runs
 * @return array{exit: int, stderr: string, source_main: string}
 */
function caddy_global_options_guard(array $fragments, ?array $publishedFragments = null, string $awk = 'awk'): array
{
    $files = new Filesystem;
    $root = sys_get_temp_dir().'/orbit-caddy-global-options-'.bin2hex(random_bytes(8));
    $files->ensureDirectoryExists("{$root}/candidate/fragments");

    foreach ($fragments as $name => $contents) {
        $files->put("{$root}/candidate/fragments/{$name}", $contents);
    }

    $sourceMain = "{$root}/Caddyfile";

    if ($publishedFragments !== null) {
        $files->ensureDirectoryExists("{$root}/versions/current/fragments");
        $sourceMain = "{$root}/versions/current/Caddyfile";

        foreach ($publishedFragments as $name => $contents) {
            $files->put($name === 'Caddyfile' ? $sourceMain : "{$root}/versions/current/fragments/{$name}", $contents);
        }
    } else {
        $files->put($sourceMain, $fragments['00-unmanaged.caddy'] ?? '');
    }

    $files->ensureDirectoryExists("{$root}/bin");
    symlink((string) (new ExecutableFinder)->find($awk), "{$root}/bin/awk");

    try {
        $process = new Process([
            'bash',
            '-eu',
            '-c',
            CaddyGlobalOptions::conflictGuard().'refuse_carried_global_options "$1" "$2"',
            'guard',
            "{$root}/candidate",
            $sourceMain,
        ], env: ['PATH' => "{$root}/bin:".getenv('PATH')]);
        $process->run();

        return ['exit' => (int) $process->getExitCode(), 'stderr' => $process->getErrorOutput(), 'source_main' => $sourceMain];
    } finally {
        $files->deleteDirectory($root);
    }
}

/**
 * Every distinct awk on this host, so the guard runs under mawk, which Ubuntu Nodes use.
 *
 * @return list<string>
 */
function caddy_global_options_awks(): array
{
    $finder = new ExecutableFinder;

    return collect(['awk', 'mawk', 'gawk'])
        ->filter(fn (string $name): bool => $finder->find($name) !== null)
        ->unique(fn (string $name): string => (string) realpath((string) $finder->find($name)))
        ->values()
        ->all();
}
