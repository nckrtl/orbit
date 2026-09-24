<?php

declare(strict_types=1);

use App\Infrastructure\Analytics\AnalyticsCaddyPublisher;
use App\Infrastructure\Caddy\CaddyGlobalOptions;
use App\Infrastructure\ProxyCli\ProxyCliCaddyPublisher;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\WebSocket\WebSocketCaddyPublisher;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

dataset('role caddy publishers', [
    'proxycli' => [fn (string $site): RemoteCommand => new ProxyCliCaddyPublisher()->command($site, '8787', '10.6.0.2')],
    'analytics' => [fn (string $site): RemoteCommand => new AnalyticsCaddyPublisher()->command($site, '8000', '10.6.0.2')],
    'websocket' => [fn (string $site): RemoteCommand => new WebSocketCaddyPublisher()->command($site, '8080', '10.6.0.2')],
]);

dataset('role caddy removals', [
    'proxycli' => [fn (): RemoteCommand => new ProxyCliCaddyPublisher()->removeCommand()],
    'analytics' => [fn (): RemoteCommand => new AnalyticsCaddyPublisher()->removeCommand()],
    'websocket' => [fn (): RemoteCommand => new WebSocketCaddyPublisher()->removeCommand()],
]);

const ROLE_CADDY_SITE = "role.orbit {\n}\n";

it('adopts a modified regular-file Caddyfile as 00-unmanaged.caddy', function (Closure $command): void {
    $result = role_caddy_run($command(ROLE_CADDY_SITE), live: 'file', liveContents: "operator.test {\n}\n", packageDefault: "package default\n");

    expect($result['exit'])
        ->toBe(0)
        ->and($result['link'])
        ->toBe("{$result['versions']}/{$result['version']}/Caddyfile")
        ->and($result['main'])
        ->toBe(CaddyGlobalOptions::render()."import {$result['versions']}/{$result['version']}/fragments/*.caddy\n")
        ->and($result['fragments'])
        ->toEqual(['00-unmanaged.caddy' => "operator.test {\n}\n", $result['owned'] => ROLE_CADDY_SITE]);
})->with('role caddy publishers');

it('replaces the unmodified package-default Caddyfile', function (Closure $command): void {
    $result = role_caddy_run($command(ROLE_CADDY_SITE), live: 'file', liveContents: "package default\n", packageDefault: "package default\n");

    expect($result['exit'])
        ->toBe(0)
        ->and($result['link'])
        ->toBe("{$result['versions']}/{$result['version']}/Caddyfile")
        ->and($result['fragments'])
        ->toEqual([$result['owned'] => ROLE_CADDY_SITE]);
})->with('role caddy publishers');

it('adopts a Caddyfile symlinked outside Orbit versions as 00-unmanaged.caddy', function (Closure $command): void {
    $result = role_caddy_run($command(ROLE_CADDY_SITE), live: 'link', liveContents: "operator.test {\n}\n");

    expect($result['exit'])
        ->toBe(0)
        ->and($result['fragments'])
        ->toEqual(['00-unmanaged.caddy' => "operator.test {\n}\n", $result['owned'] => ROLE_CADDY_SITE]);
})->with('role caddy publishers');

it('carries the other fragments of the live Orbit version', function (Closure $command): void {
    $result = role_caddy_run($command(ROLE_CADDY_SITE), live: 'versioned', liveContents: '', fragments: ['00-unmanaged.caddy' => "operator.test {\n}\n", 'app-dev.caddy' => "app.test {\n}\n"]);

    expect($result['exit'])
        ->toBe(0)
        ->and($result['fragments'])
        ->toEqual(['00-unmanaged.caddy' => "operator.test {\n}\n", 'app-dev.caddy' => "app.test {\n}\n", $result['owned'] => ROLE_CADDY_SITE]);
})->with('role caddy publishers');

it('renames a carried legacy unmanaged.caddy on publish', function (Closure $command): void {
    $result = role_caddy_run($command(ROLE_CADDY_SITE), live: 'versioned', liveContents: '', fragments: ['unmanaged.caddy' => "operator.test {\n}\n", 'app-dev.caddy' => "app.test {\n}\n"]);

    expect($result['exit'])
        ->toBe(0)
        ->and($result['fragments'])
        ->toEqual(['00-unmanaged.caddy' => "operator.test {\n}\n", 'app-dev.caddy' => "app.test {\n}\n", $result['owned'] => ROLE_CADDY_SITE]);
})->with('role caddy publishers');

it('renames a carried legacy unmanaged.caddy on removal', function (Closure $command): void {
    $owned = $command()->arguments[5];
    $result = role_caddy_run($command(), live: 'versioned', liveContents: '', fragments: ['unmanaged.caddy' => "operator.test {\n}\n", 'app-dev.caddy' => "app.test {\n}\n", $owned => ROLE_CADDY_SITE]);

    expect($result['exit'])
        ->toBe(0)
        ->and($result['fragments'])
        ->toEqual(['00-unmanaged.caddy' => "operator.test {\n}\n", 'app-dev.caddy' => "app.test {\n}\n"]);
})->with('role caddy removals');

it('fails closed when both unmanaged fragment names are carried', function (Closure $command): void {
    $result = role_caddy_run($command(ROLE_CADDY_SITE), live: 'versioned', liveContents: '', fragments: ['00-unmanaged.caddy' => "current.test {\n}\n", 'unmanaged.caddy' => "legacy.test {\n}\n"]);

    expect($result['exit'])
        ->not->toBe(0)
        ->and($result['link'])
        ->toBe("{$result['versions']}/current/Caddyfile")
        ->and($result['published'])
        ->toBeFalse()
        ->and($result['services'])
        ->toBe([]);
})->with('role caddy publishers');

it('restores the adopted regular-file Caddyfile when Caddy rejects the new version', function (Closure $command): void {
    $result = role_caddy_run($command(ROLE_CADDY_SITE), live: 'file', liveContents: "operator.test {\n}\n", packageDefault: "package default\n", failReload: true);

    expect($result['exit'])
        ->toBe(1)
        ->and($result['link'])
        ->toBeNull()
        ->and($result['main'])
        ->toBe("operator.test {\n}\n")
        ->and($result['published'])
        ->toBeFalse()
        ->and($result['leftovers'])
        ->toBe([])
        ->and($result['services'])
        ->toContain('reload-or-restart caddy', 'restart caddy');
})->with('role caddy publishers');

/**
 * Runs a role publisher's publish or removal script against a temporary /etc/caddy. Every path the script
 * touches is an argument, so only root-only commands, Caddy, and systemd are shimmed.
 *
 * @param  'file'|'link'|'versioned'  $live
 * @param  array<string, string>  $fragments
 * @return array{exit: int, stderr: string, version: string, owned: string, versions: string, link: string|null, main: string, fragments: array<string, string>, published: bool, leftovers: list<string>, services: list<string>}
 */
function role_caddy_run(
    RemoteCommand $command,
    string $live,
    string $liveContents,
    ?string $packageDefault = null,
    array $fragments = [],
    bool $failReload = false,
): array {
    $files = new Filesystem;
    $root = sys_get_temp_dir().'/orbit-role-caddy-'.bin2hex(random_bytes(8));
    $etc = "{$root}/etc/caddy";
    $versions = "{$etc}/orbit-versions";
    $liveCaddyfile = "{$etc}/Caddyfile";
    $files->ensureDirectoryExists("{$root}/bin");
    $files->ensureDirectoryExists($etc);

    $arguments = array_slice($command->arguments, 4);
    [$version, $owned] = [$arguments[0], $arguments[1]];
    $arguments[2] = $versions;
    $arguments[3] = $liveCaddyfile;
    $arguments[5] = "{$root}/caddy.lock";

    match ($live) {
        'file' => $files->put($liveCaddyfile, $liveContents),
        'link' => $files->put("{$etc}/Caddyfile.operator", $liveContents) && symlink("{$etc}/Caddyfile.operator", $liveCaddyfile),
        'versioned' => (function () use ($files, $versions, $fragments, $liveCaddyfile): void {
            $files->ensureDirectoryExists("{$versions}/current/fragments");
            $files->put("{$versions}/current/Caddyfile", "import {$versions}/current/fragments/*.caddy\n");
            foreach ($fragments as $name => $contents) {
                $files->put("{$versions}/current/fragments/{$name}", $contents);
            }
            symlink("{$versions}/current/Caddyfile", $liveCaddyfile);
        })(),
    };

    $shims = [
        'install' => <<<'BASH'
            args=()
            while [ "$#" -gt 0 ]; do
                case "$1" in -o|-g) shift 2 ;; *) args+=("$1"); shift ;; esac
            done
            exec /usr/bin/install "${args[@]}"
            BASH,
        'chown' => 'exit 0',
        'dpkg-query' => 'printf "%s %s\n" "$ROLE_CADDY_LIVE" "$ROLE_CADDY_DEFAULT_MD5"',
        'caddy' => 'exit 0',
        'systemctl' => <<<'BASH'
            printf '%s\n' "$*" >> "$ROLE_CADDY_ROOT/systemctl.log"
            case "$1" in
                is-active) exit 3 ;;
                reload-or-restart) [ "$ROLE_CADDY_FAIL_RELOAD" = 1 ] && exit 1 ;;
            esac
            exit 0
            BASH,
    ];

    foreach ($shims as $name => $body) {
        $files->put("{$root}/bin/{$name}", "#!/usr/bin/env bash\nset -eu\n{$body}\n");
        chmod("{$root}/bin/{$name}", 0o755);
    }

    try {
        $process = new Process(['bash', '-seu', '--', ...$arguments], $root, [
            'PATH' => "{$root}/bin:".getenv('PATH'),
            'ROLE_CADDY_ROOT' => $root,
            'ROLE_CADDY_LIVE' => $liveCaddyfile,
            'ROLE_CADDY_DEFAULT_MD5' => $packageDefault === null ? '' : md5($packageDefault),
            'ROLE_CADDY_FAIL_RELOAD' => $failReload ? '1' : '0',
        ]);
        $process->setInput($command->input);
        $process->run();

        $published = "{$versions}/{$version}";
        $link = is_link($liveCaddyfile) ? readlink($liveCaddyfile) : null;
        $publishedFragments = is_dir("{$published}/fragments")
            ? collect($files->files("{$published}/fragments"))
                ->mapWithKeys(fn (SplFileInfo $file): array => [$file->getFilename() => (string) file_get_contents($file->getPathname())])
                ->all()
            : [];

        return [
            'exit' => (int) $process->getExitCode(),
            'stderr' => $process->getErrorOutput(),
            'version' => $version,
            'owned' => $owned,
            'versions' => $versions,
            'link' => $link === false ? null : $link,
            'main' => (string) @file_get_contents($liveCaddyfile),
            'fragments' => $publishedFragments,
            'published' => file_exists($published),
            'leftovers' => array_values(array_map(basename(...), glob("{$etc}/.Caddyfile.orbit-*") ?: [])),
            'services' => array_values(array_filter(explode("\n", (string) @file_get_contents("{$root}/systemctl.log")))),
        ];
    } finally {
        $files->deleteDirectory($root);
    }
}
