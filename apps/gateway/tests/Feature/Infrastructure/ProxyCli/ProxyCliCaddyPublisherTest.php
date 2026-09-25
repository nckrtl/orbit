<?php

declare(strict_types=1);

use App\Infrastructure\Caddy\CaddyFragmentListeners;
use App\Infrastructure\ProxyCli\ProxyCliCaddyPublisher;
use App\Infrastructure\ProxyCli\ProxyCliCaddySiteRenderer;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

const PROXYCLI_ROUTE_FRAGMENT = <<<'CADDY'
    cli-proxy-api.orbit {
        bind 0.0.0.0
        reverse_proxy 127.0.0.1:8317
    }
    collector.cli-proxy-api.orbit {
        bind 0.0.0.0
        reverse_proxy 127.0.0.1:8787
    }

    CADDY;

const PROXYCLI_ROUTE_FRAGMENT_WITHOUT_COLLECTOR = <<<'CADDY'
    cli-proxy-api.orbit {
        bind 0.0.0.0
        reverse_proxy 127.0.0.1:8317
    }

    CADDY;

describe('the ProxyCli Caddy publication with a Route takeover', function (): void {
    it('swaps the Route fragment and the collector fragment in one reload', function (): void {
        $root = proxycli_caddy_root();

        try {
            $result = proxycli_caddy_run($root, PROXYCLI_ROUTE_FRAGMENT_WITHOUT_COLLECTOR);
            $published = dirname((string) readlink("{$root}/Caddyfile"));

            expect($result->getExitCode())->toBe(0, $result->getErrorOutput())
                ->and(basename($published))->not->toBe('live')
                ->and(file_get_contents("{$published}/fragments/app-dev.caddy"))->toBe(PROXYCLI_ROUTE_FRAGMENT_WITHOUT_COLLECTOR)
                ->and(file_get_contents("{$published}/fragments/proxycli.caddy"))
                ->toContain("collector.cli-proxy-api.orbit {\n    bind 10.44.0.17\n")
                ->and(file_get_contents("{$published}/fragments/other.caddy"))->toBe("other.orbit {\n}\n")
                ->and(proxycli_caddy_log($root, 'systemctl'))->toBe(['enable caddy', 'reload-or-restart caddy'])
                ->and(proxycli_caddy_log($root, 'caddy'))->toHaveCount(1);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('keeps the live version and does not reload when validation fails', function (): void {
        $root = proxycli_caddy_root();

        try {
            $result = proxycli_caddy_run($root, PROXYCLI_ROUTE_FRAGMENT_WITHOUT_COLLECTOR, validates: false);

            expect($result->getExitCode())->not->toBe(0)
                ->and(readlink("{$root}/Caddyfile"))->toBe("{$root}/versions/live/Caddyfile")
                ->and(file_get_contents("{$root}/versions/live/fragments/app-dev.caddy"))->toBe(PROXYCLI_ROUTE_FRAGMENT)
                ->and(proxycli_caddy_log($root, 'systemctl'))->toBe([])
                ->and(glob("{$root}/versions/*"))->toBe(["{$root}/versions/live"]);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('copies the Route fragment unchanged without a takeover', function (): void {
        $root = proxycli_caddy_root();

        try {
            $result = proxycli_caddy_run($root, null);
            $published = dirname((string) readlink("{$root}/Caddyfile"));

            expect($result->getExitCode())->toBe(0, $result->getErrorOutput())
                ->and(file_get_contents("{$published}/fragments/app-dev.caddy"))->toBe(PROXYCLI_ROUTE_FRAGMENT);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });
});

/**
 * A live fragment layout like production's collector Node: the Route fragment serves both names, and the
 * collector fragment still names the retired hostname.
 */
function proxycli_caddy_root(): string
{
    $files = new Filesystem;
    $root = sys_get_temp_dir().'/orbit-proxycli-caddy-'.bin2hex(random_bytes(8));
    $files->ensureDirectoryExists("{$root}/versions/live/fragments");
    $files->ensureDirectoryExists("{$root}/bin");
    $files->put("{$root}/versions/live/Caddyfile", "import {$root}/versions/live/fragments/*.caddy\n");
    $files->put("{$root}/versions/live/fragments/app-dev.caddy", PROXYCLI_ROUTE_FRAGMENT);
    $files->put("{$root}/versions/live/fragments/proxycli.caddy", "collector.proxycli.orbit {\n    bind 0.0.0.0\n}\n");
    $files->put("{$root}/versions/live/fragments/other.caddy", "other.orbit {\n}\n");
    symlink("{$root}/versions/live/Caddyfile", "{$root}/Caddyfile");

    $stubs = [
        'install' => "#!/usr/bin/env bash\nwhile [ \"\$1\" != -- ]; do shift; done\nshift\nmkdir -p \"\$@\"\n",
        'chown' => "#!/usr/bin/env bash\nexit 0\n",
        'ip' => "#!/usr/bin/env bash\necho '2: orbit    inet 10.44.0.17/24 scope global orbit'\n",
        'caddy' => "#!/usr/bin/env bash\necho \"\$*\" >> \"\$ORBIT_TEST_ROOT/caddy.log\"\n[ \"\$ORBIT_TEST_VALIDATES\" = 1 ]\n",
        'systemctl' => "#!/usr/bin/env bash\nif [ \"\$1\" = is-active ]; then exit 0; fi\necho \"\$*\" >> \"\$ORBIT_TEST_ROOT/systemctl.log\"\n",
    ];

    foreach ($stubs as $name => $stub) {
        $files->put("{$root}/bin/{$name}", $stub);
        chmod("{$root}/bin/{$name}", 0o755);
    }

    return $root;
}

function proxycli_caddy_run(string $root, ?string $appDevConfiguration, bool $validates = true): Process
{
    $command = new ProxyCliCaddyPublisher()->command(
        new ProxyCliCaddySiteRenderer()->render(8787),
        '8787',
        new CaddyFragmentListeners(['10.44.0.17'], ['10.44.0.17']),
        $appDevConfiguration,
    );
    $arguments = array_slice($command->arguments, 1);
    // bash -seu -- version fragment versions Caddyfile service lock replaced
    $arguments[5] = "{$root}/versions";
    $arguments[6] = "{$root}/Caddyfile";
    $arguments[8] = "{$root}/caddy.lock";

    $process = new Process($arguments, env: [
        'PATH' => "{$root}/bin:".getenv('PATH'),
        'ORBIT_TEST_ROOT' => $root,
        'ORBIT_TEST_VALIDATES' => $validates ? '1' : '0',
    ], input: $command->input);
    $process->run();

    return $process;
}

/** @return list<string> */
function proxycli_caddy_log(string $root, string $name): array
{
    $path = "{$root}/{$name}.log";

    return is_file($path) ? array_values(array_filter(explode("\n", (string) file_get_contents($path)))) : [];
}
