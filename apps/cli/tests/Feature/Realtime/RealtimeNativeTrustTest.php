<?php

declare(strict_types=1);

use App\Support\Realtime\RealtimeConnectionException;
use App\Support\Realtime\RealtimeProtocolException;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->trustRoot = sys_get_temp_dir().'/orbit-realtime-trust-'.bin2hex(random_bytes(8));
    mkdir($this->trustRoot, 0o700);
    $this->trustServers = [];
    [$this->trustCertificate, $this->trustKey] = realtime_native_certificate($this->trustRoot);
});

afterEach(function (): void {
    foreach ($this->trustServers as $server) {
        $server->stop();
    }

    new Filesystem()->deleteDirectory($this->trustRoot);
});

describe('native realtime trust intent', function (): void {
    it('keeps a selected pin mandatory despite a trusted default root', function (string $mode, string $pinState): void {
        $pin = $this->trustRoot.'/selected.pem';
        copy($this->trustCertificate, $pin);

        if ($pinState === 'missing') {
            unlink($pin);
        } elseif ($pinState === 'directory') {
            unlink($pin);
            mkdir($pin);
        } elseif ($pinState === 'unreadable') {
            chmod($pin, 0o000);

            if (is_readable($pin)) {
                $this->markTestSkipped('This user can read mode-000 files.');
            }
        } elseif ($pinState === 'wrong') {
            mkdir($this->trustRoot.'/other');
            [$other] = realtime_native_certificate($this->trustRoot.'/other');
            copy($other, $pin);
        }

        $address = realtime_native_server($this);
        $results = realtime_native_client($this, $mode, $address, $pin);

        if ($pinState === 'valid') {
            expect($results[0]['status'])->toBe($mode === 'auth' ? 'authorized' : 'connected');
            expect(file($this->trustRoot.'/trace', FILE_IGNORE_NEW_LINES))->toHaveCount(1);
        } else {
            expect($results[0]['status'])->toBe('failed')
                ->and($results[0]['type'])->toBe(RealtimeConnectionException::class)
                ->and(file_exists($this->trustRoot.'/trace'))->toBeFalse();

            if ($pinState !== 'wrong') {
                expect($results[0]['message'])->toBe('The selected realtime CA certificate is unavailable.');
            }
        }
    })->with(['auth', 'socket'])->with(['valid', 'missing', 'directory', 'unreadable', 'wrong']);

    it('uses default trust only when no pin was selected', function (string $mode): void {
        $address = realtime_native_server($this);

        $results = realtime_native_client($this, $mode, $address, null);

        expect($results[0]['status'])->toBe($mode === 'auth' ? 'authorized' : 'connected');
        expect(file($this->trustRoot.'/trace', FILE_IGNORE_NEW_LINES))->toHaveCount(1);
    })->with(['auth', 'socket']);

    it('refuses a pin removed between connection attempts', function (string $mode): void {
        $pin = $this->trustRoot.'/selected.pem';
        copy($this->trustCertificate, $pin);
        $address = realtime_native_server($this);

        $results = realtime_native_client($this, $mode, $address, $pin, removePin: true);

        expect(array_column($results, 'status'))->toBe([$mode === 'auth' ? 'authorized' : 'connected', 'failed'])
            ->and($results[1]['type'])->toBe(RealtimeConnectionException::class)
            ->and($results[1]['message'])->toBe('The selected realtime CA certificate is unavailable.');
        expect(file($this->trustRoot.'/trace', FILE_IGNORE_NEW_LINES))->toHaveCount(1);
    })->with(['auth', 'socket']);

    it('does not follow a redirect or accept a redirect body as authorization', function (int $status): void {
        $alternate = realtime_native_server($this, ['tls' => false, 'trace' => $this->trustRoot.'/alternate-trace']);
        $address = realtime_native_server($this, ['status' => $status, 'location' => 'http://'.$alternate.'/elsewhere']);

        $results = realtime_native_client($this, 'auth', $address, $this->trustCertificate);

        expect($results[0]['status'])->toBe('failed')
            ->and($results[0]['type'])->toBe(RealtimeConnectionException::class)
            ->and($results[0]['message'])->toBe("Realtime channel authorization failed with HTTP status {$status}.")
            ->and(file_exists($this->trustRoot.'/alternate-trace'))->toBeFalse();
        $requests = file($this->trustRoot.'/trace', FILE_IGNORE_NEW_LINES);
        expect($requests)->toHaveCount(1);
        $request = json_decode($requests[0], true, flags: JSON_THROW_ON_ERROR);
        expect($request['headers'])->toStartWith('POST /api/v1/broadcasting/auth HTTP/1.1')
            ->not->toContain('Authorization:');
        expect(json_decode($request['body'], true, flags: JSON_THROW_ON_ERROR))->toBe(['socket_id' => '1.1', 'channel_name' => 'private-orbit']);
    })->with([301, 302, 303, 307, 308]);

    it('keeps native non-success malformed and timeout responses bounded', function (array $response, string $exception, string $message): void {
        $address = realtime_native_server($this, $response);

        $results = realtime_native_client($this, 'auth', $address, $this->trustCertificate);

        expect($results)->toBe([['status' => 'failed', 'type' => $exception, 'message' => $message]]);
    })->with([
        'not found' => [['status' => 404], RealtimeConnectionException::class, 'Realtime channel authorization failed with HTTP status 404.'],
        'missing auth' => [['body' => []], RealtimeProtocolException::class, 'Realtime channel authorization response omitted the auth signature.'],
        'invalid auth' => [['body' => ['auth' => []]], RealtimeProtocolException::class, 'Realtime channel authorization response omitted the auth signature.'],
        'timeout' => [['delay_us' => 2_000_000], RealtimeConnectionException::class, 'Could not reach the gateway to authorize the realtime channel.'],
    ]);
});

/** @return array{string, string} */
function realtime_native_certificate(string $directory): array
{
    $configuration = $directory.'/openssl.cnf';
    file_put_contents($configuration, <<<'OPENSSL'
        [req]
        distinguished_name = subject
        x509_extensions = v3_ca
        prompt = no
        [subject]
        CN = 127.0.0.1
        [v3_ca]
        subjectAltName = IP:127.0.0.1
        basicConstraints = critical, CA:TRUE
        keyUsage = critical, keyCertSign, digitalSignature
        OPENSSL);
    $key = openssl_pkey_new(['config' => $configuration, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $certificate = openssl_csr_sign(openssl_csr_new(['commonName' => '127.0.0.1'], $key, ['config' => $configuration]), null, $key, 1, ['config' => $configuration, 'x509_extensions' => 'v3_ca']);
    openssl_x509_export($certificate, $pem);
    openssl_pkey_export($key, $privateKey, null, ['config' => $configuration]);
    file_put_contents($directory.'/ca.pem', $pem);
    file_put_contents($directory.'/key.pem', $privateKey);
    chmod($directory.'/key.pem', 0o600);

    return [$directory.'/ca.pem', $directory.'/key.pem'];
}

/** @param array<string, mixed> $response */
function realtime_native_server(object $test, array $response = []): string
{
    $configuration = $test->trustRoot.'/server-'.count($test->trustServers).'.json';
    file_put_contents($configuration, json_encode([
        'certificate' => $test->trustCertificate,
        'key' => $test->trustKey,
        'trace' => $test->trustRoot.'/trace',
        ...$response,
    ], JSON_THROW_ON_ERROR));
    $server = new Process([PHP_BINARY, dirname(__DIR__, 2).'/Fixtures/realtime/trust-server.php', $configuration]);
    $test->trustServers[] = $server;
    $server->start();
    $server->waitUntil(fn (string $type, string $output): bool => str_contains($server->getOutput(), "\n"));

    return trim($server->getOutput());
}

/** @return list<array<string, mixed>> */
function realtime_native_client(object $test, string $mode, string $address, ?string $pin, bool $removePin = false): array
{
    $configuration = $test->trustRoot.'/client.json';
    file_put_contents($configuration, json_encode([
        'mode' => $mode,
        'url' => ($mode === 'auth' ? 'https://' : 'wss://').$address,
        'pin' => $pin,
        'remove_pin' => $removePin,
    ], JSON_THROW_ON_ERROR));
    $client = new Process([
        PHP_BINARY, '-d', 'openssl.cafile='.$test->trustCertificate, '-d', 'curl.cainfo='.$test->trustCertificate,
        dirname(__DIR__, 2).'/Fixtures/realtime/trust-client.php', $configuration,
    ], env: ['ORBIT_HOME' => $test->trustRoot.'/home', 'NO_PROXY' => '127.0.0.1']);
    $client->mustRun();
    expect($client->getErrorOutput())->toBe('');

    return json_decode($client->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}
