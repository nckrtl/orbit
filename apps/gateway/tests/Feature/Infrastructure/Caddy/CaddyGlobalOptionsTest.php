<?php

declare(strict_types=1);

use App\Infrastructure\Analytics\AnalyticsCaddyPublisher;
use App\Infrastructure\AppDev\AppDevCaddyPublisher;
use App\Infrastructure\AppProd\AppProdCaddyPublisher;
use App\Infrastructure\Caddy\CaddyGlobalOptions;
use App\Infrastructure\Metrics\MetricsCaddyPublisher;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\ProxyCli\ProxyCliCaddyPublisher;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\WebSocket\WebSocketCaddyPublisher;
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

it('refuses carried global options right before every Caddy publisher validates its candidate', function (Closure $script): void {
    $program = $script();
    $validations = preg_match_all('/validate --config/', $program);

    expect($program)
        ->toStartWith(CaddyGlobalOptions::conflictGuard())
        ->not->toContain('{$this->')
        ->and($validations)
        ->toBeGreaterThan(0)
        ->and(preg_match_all('/^\s*refuse_carried_global_options "\$candidate" "\$source_main"\n[^\n]*validate --config/m', $program))
        ->toBe($validations);
})->with([
    'app-dev publish' => fn (): string => new AppDevCaddyPublisher()->command("app.test {\n}\n", 'version')->input,
    'app-dev removal' => fn (): string => new AppDevCaddyPublisher()->removeCommand('version')->input,
    'app-prod publish' => fn (): string => new AppProdCaddyPublisher()->command("app.test {\n}\n", 'version')->input,
    'app-prod removal' => fn (): string => new AppProdCaddyPublisher()->removeCommand('version')->input,
    'websocket publish' => fn (): string => new WebSocketCaddyPublisher()->command("ws.test {\n}\n", '8080', '10.6.0.2')->input,
    'websocket removal' => fn (): string => new WebSocketCaddyPublisher()->removeCommand()->input,
    'analytics publish' => fn (): string => new AnalyticsCaddyPublisher()->command("stats.test {\n}\n", '8000', '10.6.0.2')->input,
    'analytics removal' => fn (): string => new AnalyticsCaddyPublisher()->removeCommand()->input,
    'proxycli publish' => fn (): string => new ProxyCliCaddyPublisher()->command("proxy.test {\n}\n", '8317', '10.6.0.2')->input,
    'proxycli removal' => fn (): string => new ProxyCliCaddyPublisher()->removeCommand()->input,
    'metrics publish' => fn (): string => caddy_global_options_metrics_script(fn (MetricsCaddyPublisher $publisher) => $publisher->publish("metrics.orbit {\n}\n")),
    'metrics withdrawal' => fn (): string => caddy_global_options_metrics_script(fn (MetricsCaddyPublisher $publisher) => $publisher->withdrawForCutover()),
]);

it('passes Orbit global options to a removal script as an argument', function (Closure $command): void {
    $command = $command();

    expect(base64_decode((string) collect($command->arguments)->last(), true))
        ->toBe(CaddyGlobalOptions::render())
        ->and($command->input)
        ->toContain('global_options=$7')
        ->toContain('printf \'%s\\n\' "$global_options" | base64 --decode > "$candidate/Caddyfile"');
})->with([
    'analytics' => fn (): RemoteCommand => new AnalyticsCaddyPublisher()->removeCommand(),
    'proxycli' => fn (): RemoteCommand => new ProxyCliCaddyPublisher()->removeCommand(),
]);

it('writes Orbit global options into the ProxyCli candidate before its import', function (): void {
    $input = new ProxyCliCaddyPublisher()->command("proxy.test {\n}\n", '8317', '10.6.0.2')->input;

    expect(substr_count($input, "'".base64_encode(CaddyGlobalOptions::render())."' | base64 --decode > \"\$candidate/Caddyfile\""))
        ->toBe(2)
        ->and(substr_count($input, '>> "$candidate/Caddyfile"'))
        ->toBe(2);
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

/** @param  Closure(MetricsCaddyPublisher): mixed  $operation */
function caddy_global_options_metrics_script(Closure $operation): string
{
    $processes = new class implements ProcessRunner
    {
        public ?ProcessInvocation $invocation = null;

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->invocation = $invocation;

            return new CommandResult(0, "orbit-metrics-publication:created\n", '', 1, false);
        }
    };

    $operation(new MetricsCaddyPublisher($processes));

    return (string) $processes->invocation?->input;
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
