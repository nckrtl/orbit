<?php

declare(strict_types=1);

use Orbit\HerdrObserver\ChildProcess;
use Orbit\HerdrObserver\Configuration;
use Orbit\HerdrObserver\NonceStore;

require_once dirname(__DIR__, 4).'/resources/herdr-observer.php';

it('streams receive-only Herdr frames and rejects a replay after restart', function (): void {
    $directory = sys_get_temp_dir().'/orbit-herdr-observer-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $keys = herdr_observer_keys();
    file_put_contents($directory.'/jwks.json', json_encode(['keys' => [$keys['jwk']]], JSON_THROW_ON_ERROR));
    $nonceStore = $directory.'/nonces.json';
    $fixture = dirname(__DIR__, 3).'/Fixtures/herdr-observer-fake';
    $token = herdr_observer_token($keys['private'], 'https://tasks.commander.test', str_repeat('a', 32));
    $server = herdr_observer_start($fixture, $directory.'/jwks.json', $nonceStore);

    try {
        $client = herdr_observer_connect($server['port']);
        herdr_observer_handshake($client, $token, 'https://tasks.commander.test');
        $headers = herdr_observer_headers($client);
        $frame = json_decode(herdr_observer_message($client), true, flags: JSON_THROW_ON_ERROR);
        $incrementalFrame = json_decode(herdr_observer_message($client), true, flags: JSON_THROW_ON_ERROR);
        $closed = json_decode(herdr_observer_message($client), true, flags: JSON_THROW_ON_ERROR);

        expect($headers)->toStartWith('HTTP/1.1 101 Switching Protocols')
            ->and($frame['type'])->toBe('terminal.frame')
            ->and($frame['full'])->toBeTrue()
            ->and(base64_decode($frame['bytes'], true))->toContain('receive only')
            ->and($incrementalFrame['type'])->toBe('terminal.frame')
            ->and($incrementalFrame['full'])->toBeFalse()
            ->and($incrementalFrame['seq'])->toBe(2)
            ->and($closed['type'])->toBe('terminal.closed');

        fclose($client);
    } finally {
        herdr_observer_stop($server['process'], $server['pipes']);
    }

    $restarted = herdr_observer_start($fixture, $directory.'/jwks.json', $nonceStore);

    try {
        $replay = herdr_observer_connect($restarted['port']);
        herdr_observer_handshake($replay, $token, 'https://tasks.commander.test');
        expect(herdr_observer_headers($replay))->toStartWith('HTTP/1.1 403 Forbidden');
        fclose($replay);

        $wrongOrigin = herdr_observer_connect($restarted['port']);
        herdr_observer_handshake(
            $wrongOrigin,
            herdr_observer_token($keys['private'], 'https://tasks.commander.test', str_repeat('b', 32)),
            'https://attacker.test',
        );
        expect(herdr_observer_headers($wrongOrigin))->toStartWith('HTTP/1.1 403 Forbidden');
        fclose($wrongOrigin);
    } finally {
        herdr_observer_stop($restarted['process'], $restarted['pipes']);
        @unlink($directory.'/jwks.json');
        @unlink($nonceStore);
        @rmdir($directory);
    }
});

it('closes with policy violation when a browser sends application data', function (): void {
    $directory = sys_get_temp_dir().'/orbit-herdr-observer-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $keys = herdr_observer_keys();
    file_put_contents($directory.'/jwks.json', json_encode(['keys' => [$keys['jwk']]], JSON_THROW_ON_ERROR));
    $nonceStore = $directory.'/nonces.json';
    $fixture = dirname(__DIR__, 3).'/Fixtures/herdr-observer-fake';
    $token = herdr_observer_token($keys['private'], 'https://tasks.commander.test', str_repeat('c', 32));
    $server = herdr_observer_start($fixture, $directory.'/jwks.json', $nonceStore);

    try {
        $client = herdr_observer_connect($server['port']);
        herdr_observer_handshake($client, $token, 'https://tasks.commander.test');
        expect(herdr_observer_headers($client))->toStartWith('HTTP/1.1 101 Switching Protocols');
        herdr_observer_send_client_message($client, 'input is forbidden');
        $close = unpack('ncode', herdr_observer_message($client));

        expect($close['code'])->toBe(1008);
        fclose($client);
    } finally {
        herdr_observer_stop($server['process'], $server['pipes']);
        @unlink($directory.'/jwks.json');
        @unlink($nonceStore);
        @rmdir($directory);
    }
});

it('requires a fixed loopback listener and absolute observer paths', function (): void {
    expect(fn () => Configuration::fromArguments([
        'observer.php',
        '--listen=0.0.0.0:7411',
        '--node=beast',
        '--session=commander-tasks',
        '--jwks=/tmp/jwks.json',
        '--nonce-store=/tmp/nonces.json',
        '--herdr=/usr/bin/herdr',
    ]))->toThrow(RuntimeException::class, 'Invalid observer listen address');
});

it('prunes expired nonces before it applies the live-entry capacity', function (): void {
    $path = sys_get_temp_dir().'/orbit-herdr-nonces-'.bin2hex(random_bytes(8)).'.json';
    $expired = array_fill_keys(
        array_map(static fn (int $index): string => str_pad(dechex($index), 32, '0', STR_PAD_LEFT), range(1, 1024)),
        time() - 1,
    );
    $expiresAt = time() + 60;
    file_put_contents($path, json_encode($expired, JSON_THROW_ON_ERROR));

    try {
        (new NonceStore($path))->consume(str_repeat('f', 32), $expiresAt);

        expect(json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR))
            ->toBe([str_repeat('f', 32) => $expiresAt]);
    } finally {
        @unlink($path);
    }
});

it('refuses a nonce when all replay-protection entries are still live', function (): void {
    $path = sys_get_temp_dir().'/orbit-herdr-nonces-'.bin2hex(random_bytes(8)).'.json';
    $live = array_fill_keys(
        array_map(static fn (int $index): string => str_pad(dechex($index), 32, '0', STR_PAD_LEFT), range(1, 1024)),
        time() + 60,
    );
    file_put_contents($path, json_encode($live, JSON_THROW_ON_ERROR));

    try {
        expect(fn () => (new NonceStore($path))->consume(str_repeat('f', 32), time() + 60))
            ->toThrow(RuntimeException::class, 'Replay protection capacity exceeded.');
        expect(json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR))
            ->toBe($live);
    } finally {
        @unlink($path);
    }
});

it('escalates from TERM to KILL when a Herdr child does not stop', function (): void {
    $pipes = [];
    $process = proc_open([
        PHP_BINARY,
        '-r',
        'pcntl_async_signals(true); pcntl_signal(SIGTERM, static fn () => null); fwrite(STDOUT, "ready\\n"); while (true) { usleep(10000); }',
    ], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, null, null, ['bypass_shell' => true]);
    expect($process)->toBeResource();
    expect(trim((string) fgets($pipes[1])))->toBe('ready');
    $startedAt = microtime(true);

    ChildProcess::terminate($process, $pipes);

    expect(microtime(true) - $startedAt)->toBeLessThan(3.0);
});

/** @return array{private: OpenSSLAsymmetricKey, jwk: array<string, string>} */
function herdr_observer_keys(): array
{
    $private = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    expect($private)->toBeInstanceOf(OpenSSLAsymmetricKey::class);
    $details = openssl_pkey_get_details($private);
    expect($details)->toBeArray()->toHaveKey('rsa');

    return [
        'private' => $private,
        'jwk' => [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => 'integration-key',
            'n' => herdr_observer_base64_url($details['rsa']['n']),
            'e' => herdr_observer_base64_url($details['rsa']['e']),
        ],
    ];
}

function herdr_observer_token(OpenSSLAsymmetricKey $private, string $origin, string $nonce): string
{
    $now = time();
    $header = herdr_observer_base64_url(json_encode([
        'alg' => 'RS256',
        'typ' => 'JWT',
        'kid' => 'integration-key',
    ], JSON_THROW_ON_ERROR));
    $payload = herdr_observer_base64_url(json_encode([
        'iss' => 'orbit-gateway',
        'aud' => 'herdr-observe',
        'sub' => 'terminal.observe',
        'node' => 'beast',
        'session' => 'commander-tasks',
        'pane' => 'wH:p3',
        'terminal' => 'term_65b6c6719fb573',
        'cols' => 120,
        'rows' => 40,
        'origin' => $origin,
        'jti' => $nonce,
        'iat' => $now,
        'exp' => $now + 60,
    ], JSON_THROW_ON_ERROR));
    $unsigned = $header.'.'.$payload;
    $signature = '';
    expect(openssl_sign($unsigned, $signature, $private, OPENSSL_ALGO_SHA256))->toBeTrue();

    return $unsigned.'.'.herdr_observer_base64_url($signature);
}

function herdr_observer_base64_url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

/** @return array{process: resource, pipes: array<int, resource>, port: int} */
function herdr_observer_start(string $fixture, string $jwks, string $nonceStore): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    expect($probe)->toBeResource();
    $address = stream_socket_get_name($probe, false);
    fclose($probe);
    $port = (int) substr(strrchr((string) $address, ':'), 1);
    $pipes = [];
    $process = proc_open([
        PHP_BINARY,
        dirname(__DIR__, 4).'/resources/herdr-observer.php',
        '--listen=127.0.0.1:'.$port,
        '--node=beast',
        '--session=commander-tasks',
        '--jwks='.$jwks,
        '--nonce-store='.$nonceStore,
        '--herdr='.$fixture,
    ], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    expect($process)->toBeResource();

    return ['process' => $process, 'pipes' => $pipes, 'port' => $port];
}

/** @return resource */
function herdr_observer_connect(int $port)
{
    $deadline = microtime(true) + 3;

    do {
        $client = @stream_socket_client('tcp://127.0.0.1:'.$port, $errorNumber, $errorMessage, 0.1);

        if (is_resource($client)) {
            stream_set_timeout($client, 3);

            return $client;
        }

        usleep(20_000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException($errorMessage !== '' ? $errorMessage : "Could not connect ({$errorNumber}).");
}

/** @param resource $client */
function herdr_observer_handshake($client, string $token, string $origin): void
{
    $key = base64_encode(random_bytes(16));
    fwrite($client, 'GET /?access_token='.rawurlencode($token)." HTTP/1.1\r\nHost: observer.test\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\nOrigin: {$origin}\r\n\r\n");
}

/** @param resource $client */
function herdr_observer_headers($client): string
{
    $headers = '';

    while (! str_contains($headers, "\r\n\r\n")) {
        $chunk = fread($client, 1);

        if (! is_string($chunk) || $chunk === '') {
            break;
        }

        $headers .= $chunk;
    }

    return $headers;
}

/** @param resource $client */
function herdr_observer_message($client): string
{
    $header = herdr_observer_read($client, 2);
    $length = ord($header[1]) & 0x7F;

    if ($length === 126) {
        $length = unpack('nlength', herdr_observer_read($client, 2))['length'];
    } elseif ($length === 127) {
        $parts = unpack('Nhigh/Nlow', herdr_observer_read($client, 8));
        expect($parts['high'])->toBe(0);
        $length = $parts['low'];
    }

    return herdr_observer_read($client, $length);
}

/** @param resource $client */
function herdr_observer_send_client_message($client, string $payload): void
{
    $mask = random_bytes(4);
    $masked = '';

    foreach (str_split($payload) as $index => $byte) {
        $masked .= $byte ^ $mask[$index % 4];
    }

    fwrite($client, chr(0x81).chr(0x80 | strlen($payload)).$mask.$masked);
}

/** @param resource $client */
function herdr_observer_read($client, int $length): string
{
    $bytes = '';

    while (strlen($bytes) < $length) {
        $chunk = fread($client, $length - strlen($bytes));

        if (! is_string($chunk) || $chunk === '') {
            throw new RuntimeException('Observer connection closed early.');
        }

        $bytes .= $chunk;
    }

    return $bytes;
}

/** @param resource $process @param array<int, resource> $pipes */
function herdr_observer_stop($process, array $pipes): void
{
    proc_terminate($process);

    foreach ($pipes as $pipe) {
        fclose($pipe);
    }

    proc_close($process);
}
