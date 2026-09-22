<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** @return array{root:string,placement:array<string,mixed>,socket:resource} */
function productionProbeFixture(): array
{
    $root = temporaryPath('orbit-production-probe-', 5);
    $release = $root.'/production/releases/one';
    foreach (['bin', 'state', 'caddy', 'production/releases/one/public', 'production/releases/one/.git', 'production/releases/one/vendor', 'production/releases/one/storage', 'production/releases/one/bootstrap/cache'] as $directory) {
        mkdir($root.'/'.$directory, 0o700, true);
    }
    file_put_contents($release.'/artisan', '<?php exit(0);');
    file_put_contents($release.'/.env', "APP_KEY=base64:test\n");
    file_put_contents($root.'/production/.env', "APP_KEY=base64:test\n");
    file_put_contents($release.'/composer.lock', 'locked');
    file_put_contents($release.'/vendor/autoload.php', 'autoloaded');
    file_put_contents($release.'/vendor/.orbit-e2e-composer-lock', hash_file('sha256', $release.'/composer.lock'));
    symlink($release, $root.'/production/current');
    file_put_contents($root.'/managed-root-ca.crt', 'managed CA');
    file_put_contents($root.'/local-ca.crt', 'local CA');
    file_put_contents($root.'/state/caddy-ca-path', $root.'/local-ca.crt');

    $socket = stream_socket_server('unix://'.$root.'/production/php.sock', $errorCode, $errorMessage);
    if ($socket === false) {
        throw new RuntimeException($errorMessage, $errorCode);
    }
    $owner = trim(new Process(['stat', '-c', '%U', '--', $root.'/production'])->mustRun()->getOutput());
    $placement = [
        'layout' => 'release',
        'instance_id' => 5,
        'user' => $owner,
        'home' => $root.'/production',
        'checkout_path' => $root.'/production/current',
        'effective_root' => $root.'/production/current/public',
        'environment_path' => $root.'/production/.env',
        'database_path' => null,
        'service' => 'orbit-production-php8.5-fpm.service',
        'socket' => $root.'/production/php.sock',
        'current_target' => $release,
        'domain' => 'e2e-prod.app-prod',
    ];
    file_put_contents($root.'/caddy/Caddyfile', "https://e2e-prod.app-prod {\nroot * {$placement['effective_root']}\nphp_fastcgi unix/{$placement['socket']}\n}\n");
    $commands = [
        'sudo' => 'shift 3; exec "$@"',
        'systemctl' => '[[ "$1" == is-active ]]; printf "active\n"',
        'caddy' => '[[ "$1" == validate ]]',
        'git' => <<<'BASH'
            case "$*" in
              *'remote get-url origin') printf '%s\n' https://github.com/laravel/laravel.git ;;
              *'rev-parse HEAD') printf 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n' ;;
              *) exit 70 ;;
            esac
            BASH,
        'curl' => <<<'BASH'
            /usr/bin/php -r 'echo json_encode(array_slice($argv, 1), JSON_THROW_ON_ERROR), "\n";' -- "$@" >>"$PROBE_FIXTURE_ROOT/curl.jsonl"
            exit "${PROBE_CURL_EXIT:-0}"
            BASH,
    ];
    foreach ($commands as $name => $body) {
        file_put_contents($root.'/bin/'.$name, "#!/usr/bin/env bash\nset -euo pipefail\n".$body."\n");
        chmod($root.'/bin/'.$name, 0o700);
    }
    foreach (['converge-sample-app.sh', 'verify-topology.sh'] as $script) {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/resources/guest/'.$script);
        file_put_contents($root.'/'.$script, str_replace(
            ['/usr/local/share/ca-certificates/orbit-managed-root-ca.crt', '/var/lib/orbit-e2e', '/etc/caddy'],
            [$root.'/managed-root-ca.crt', $root.'/state', $root.'/caddy'],
            $source,
        ));
    }

    return ['root' => $root, 'placement' => $placement, 'socket' => $socket];
}

/** @param array{root:string,placement:array<string,mixed>,socket:resource} $fixture */
function productionProbeProcess(array $fixture, string $operation, ?string $address, int $curlExit = 0): Process
{
    $encoded = base64_encode(json_encode($fixture['placement'], JSON_THROW_ON_ERROR));
    $arguments = $operation === 'hydrate'
        ? ['converge-sample-app.sh', 'hydrate', str_repeat('a', 40), 'app-prod', $encoded]
        : ['verify-topology.sh', 'laravel.prod', 'readiness', str_repeat('a', 40), 'orbit-e2e-probe-app-prod', $encoded];
    $arguments[0] = $fixture['root'].'/'.$arguments[0];
    if ($address !== null) {
        $arguments[] = $address;
    }

    return new Process(['bash', ...$arguments], env: [
        'PATH' => $fixture['root'].'/bin:'.getenv('PATH'),
        'PROBE_FIXTURE_ROOT' => $fixture['root'],
        'PROBE_CURL_EXIT' => (string) $curlExit,
    ]);
}

it('uses only the declared production HTTPS destination and preserves curl failure', function (string $operation, string $address, int $curlExit): void {
    $fixture = productionProbeFixture();
    try {
        $process = productionProbeProcess($fixture, $operation, $address, $curlExit);
        expect($process->run())->toBe($curlExit, $process->getErrorOutput());
        $calls = file($fixture['root'].'/curl.jsonl', FILE_IGNORE_NEW_LINES);
        expect($calls)->toHaveCount(1);
        expect(json_decode($calls[0], true, flags: JSON_THROW_ON_ERROR))->toBe([
            '--fail', '--silent', '--show-error', '--retry', '10', '--retry-delay', '2',
            '--retry-connrefused', '--retry-all-errors', '--connect-timeout', '10', '--max-time', '30',
            '--cacert', $fixture['root'].'/managed-root-ca.crt',
            '--resolve', 'e2e-prod.app-prod:443:'.$address, 'https://e2e-prod.app-prod/',
        ]);
    } finally {
        fclose($fixture['socket']);
        new Filesystem()->deleteDirectory($fixture['root']);
    }
})->with(['hydrate', 'verify'])->with(['shared Router' => '10.44.0.1', 'direct Node' => '127.0.0.1'])->with(['healthy' => 0, 'failed HTTPS' => 60]);

it('rejects a missing or unsupported production destination before curl', function (string $operation, ?string $address): void {
    $fixture = productionProbeFixture();
    try {
        $process = productionProbeProcess($fixture, $operation, $address);
        expect($process->run())->not->toBe(0);
        expect(file_exists($fixture['root'].'/curl.jsonl'))->toBeFalse();
    } finally {
        fclose($fixture['socket']);
        new Filesystem()->deleteDirectory($fixture['root']);
    }
})->with(['hydrate', 'verify'])->with([
    'missing' => null,
    'empty' => '',
    'other Node' => '10.44.0.3',
    'hostname' => 'gateway.orbit',
    'curl option' => '--insecure',
]);
