#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Domain\WireGuard\WireGuardEndpoint;
use Symfony\Component\Process\Process;

$source = getenv('ORBIT_SOURCE');
$source = is_string($source) && $source !== '' ? $source : '/home/orbit/orbit';

require $source.'/apps/gateway/vendor/autoload.php';

$temporaryDirectory = sys_get_temp_dir().'/orbit-orb-158-'.getmypid();
$failure = null;

try {
    if (! mkdir($temporaryDirectory, 0o700) && ! is_dir($temporaryDirectory)) {
        throw new RuntimeException("Could not create temporary directory [{$temporaryDirectory}].");
    }

    assertSameEndpoint('192.0.2.10:51820', WireGuardEndpoint::format('192.0.2.10', 51_820));
    assertSameEndpoint('Vpn.Example.test:51820', WireGuardEndpoint::format('Vpn.Example.test', 51_820));

    $ipv6Endpoint = WireGuardEndpoint::format('2001:db8::10', 51_820);
    assertSameEndpoint('[2001:db8::10]:51820', $ipv6Endpoint);

    foreach (
        [
            'bare IPv6' => '2001:db8::10:51820',
            'invalid port' => '192.0.2.10:0',
            'whitespace' => 'vpn.example.test :51820',
            'control character' => "192.0.2.10:51820\nPostUp = true",
        ] as $case => $endpoint
    ) {
        if (WireGuardEndpoint::isValid($endpoint)) {
            throw new RuntimeException("The {$case} endpoint unexpectedly passed validation.");
        }
    }

    proveBootstrapRefusesBeforeEffects($temporaryDirectory, $source);
    proveKernelAcceptsEndpoint($ipv6Endpoint);
} catch (Throwable $throwable) {
    $failure = $throwable;
} finally {
    removeDirectory($temporaryDirectory);
}

if ($failure instanceof Throwable) {
    fwrite(STDERR, $failure->getMessage().PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "WireGuard IPv6 endpoint boundary passed.\n");

function assertSameEndpoint(string $expected, string $actual): void
{
    if ($actual !== $expected || ! WireGuardEndpoint::isValid($actual)) {
        throw new RuntimeException("Expected endpoint [{$expected}], received [{$actual}].");
    }
}

function proveBootstrapRefusesBeforeEffects(string $temporaryDirectory, string $source): void
{
    $bootstrapHome = $temporaryDirectory.'/bootstrap';

    if (! mkdir($bootstrapHome, 0o700) && ! is_dir($bootstrapHome)) {
        throw new RuntimeException("Could not create bootstrap home [{$bootstrapHome}].");
    }

    $database = $bootstrapHome.'/gateway.sqlite';

    if (file_put_contents($database, '') === false) {
        throw new RuntimeException("Could not create bootstrap database [{$database}].");
    }

    $environment = [
        'APP_ENV' => 'testing',
        'DB_DATABASE' => $database,
        'ORBIT_HOME' => $bootstrapHome,
    ];
    runProcess(
        ['php', 'artisan', 'migrate', '--force', '--no-interaction'],
        environment: $environment,
        workingDirectory: $source.'/apps/gateway',
    );

    $bootstrap = runProcess(
        [
            'php',
            'artisan',
            'orbit:bootstrap',
            '192.0.2.10',
            '--wireguard-ip=10.44.0.1',
            '--wireguard-endpoint=2001:db8::10:51820',
            '--no-interaction',
        ],
        environment: $environment,
        expectSuccess: false,
        workingDirectory: $source.'/apps/gateway',
    );

    if ($bootstrap->isSuccessful()) {
        throw new RuntimeException('Bootstrap accepted a bare IPv6 endpoint.');
    }

    $diagnostic = $bootstrap->getOutput().$bootstrap->getErrorOutput();

    if (! str_contains($diagnostic, 'Gateway WireGuard endpoint [2001:db8::10:51820] is invalid.')) {
        throw new RuntimeException('Bootstrap did not report the stable invalid-endpoint diagnostic.');
    }

    $databaseConnection = new PDO('sqlite:'.$database);
    $nodeCount = $databaseConnection->query('SELECT COUNT(*) FROM nodes')?->fetchColumn();

    if ((int) $nodeCount !== 0) {
        throw new RuntimeException('Bootstrap persisted a Node before rejecting the endpoint.');
    }

    foreach (['ca', 'generated', 'logs', 'ssh', 'wireguard'] as $directory) {
        if (file_exists($bootstrapHome.'/'.$directory)) {
            throw new RuntimeException("Bootstrap created [{$directory}] before rejecting the endpoint.");
        }
    }
}

function proveKernelAcceptsEndpoint(string $endpoint): void
{
    $privateKey = trim(runProcess(['wg', 'genkey'])->getOutput());
    $peerPrivateKey = trim(runProcess(['wg', 'genkey'])->getOutput());
    $peerPublicKey = trim(runProcess(['wg', 'pubkey'], $peerPrivateKey.PHP_EOL)->getOutput());
    $contents = <<<CONFIG
        [Interface]
        PrivateKey = {$privateKey}

        [Peer]
        PublicKey = {$peerPublicKey}
        Endpoint = {$endpoint}
        AllowedIPs = 10.44.0.1/32
        CONFIG;

    $networkNamespaceScript = <<<'BASH'
        peer_public_key=$1
        expected_endpoint=$2
        interface=orb158proof
        configuration=
        cleanup() {
            ip link delete dev "$interface" 2>/dev/null || true
            if [ -n "$configuration" ] && [ -e "$configuration" ]; then
                unlink "$configuration"
            fi
        }
        trap cleanup EXIT
        umask 077
        configuration=$(mktemp /etc/wireguard/orbit-orb-158.XXXXXX.conf)
        cat > "$configuration"
        ip link add dev "$interface" type wireguard
        wg setconf "$interface" "$configuration"
        actual=$(wg show "$interface" endpoints)
        expected="$peer_public_key"$'\t'"$expected_endpoint"
        if [ "$actual" != "$expected" ]; then
            printf 'Expected kernel endpoint [%s], received [%s].\n' "$expected" "$actual" >&2
            exit 1
        fi
        cleanup
        trap - EXIT
        BASH;

    runProcess(
        ['sudo', '-n', 'unshare', '--net', '/bin/bash', '-ceu', $networkNamespaceScript, '--', $peerPublicKey, $endpoint],
        $contents.PHP_EOL,
    );
}

/** @param list<string> $command @param array<string, string> $environment */
function runProcess(
    array $command,
    ?string $input = null,
    array $environment = [],
    bool $expectSuccess = true,
    ?string $workingDirectory = null,
): Process {
    $process = new Process($command, $workingDirectory, $environment, $input, 60.0);
    $process->run();

    if ($expectSuccess && ! $process->isSuccessful()) {
        $message = trim($process->getErrorOutput().PHP_EOL.$process->getOutput());

        throw new RuntimeException("Command [{$process->getCommandLine()}] failed: {$message}");
    }

    return $process;
}

function removeDirectory(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $path) {
        $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
    }

    rmdir($directory);
}
