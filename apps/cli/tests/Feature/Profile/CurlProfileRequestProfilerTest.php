<?php

declare(strict_types=1);

use App\Services\Profile\ProfileRequestProfiler;

describe(ProfileRequestProfiler::class, function (): void {
    it('uses its local timeout instead of the active gateway timeout', function (): void {
        $server = startSlowCurlProfileHttpTestServer();

        config()->set('orbit.gateway.timeout', 1);
        app()->forgetInstance(ProfileRequestProfiler::class);

        try {
            $profile = app(ProfileRequestProfiler::class)
                ->profile("http://127.0.0.1:{$server['port']}/");
        } finally {
            stopSlowCurlProfileHttpTestServer($server);
        }

        expect($profile['request']['completed'])
            ->toBeTrue()
            ->and($profile['request']['status'])
            ->toBe(200)
            ->and($profile['error'])
            ->toBeNull()
            ->and($profile['timings']['total_ms'])
            ->toBeGreaterThan(1000.0);
    });

    it('performs one GET without following redirects and preserves response headers', function (): void {
        $server = startSlowCurlProfileHttpTestServer();
        $toolbar = base64_encode(json_encode(['queries' => ['count' => 1]], JSON_THROW_ON_ERROR));

        app()->forgetInstance(ProfileRequestProfiler::class);

        try {
            $profile = app(ProfileRequestProfiler::class)->profile(
                "http://127.0.0.1:{$server['port']}/index.php?redirect=1",
                ['X-TOOLBAR-AUTH' => 'guest'],
            );
            $requests = array_map(
                static fn (string $line): array => json_decode(
                    $line,
                    associative: true,
                    flags: JSON_THROW_ON_ERROR,
                ),
                file($server['capture'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
            );
        } finally {
            stopSlowCurlProfileHttpTestServer($server);
        }

        expect($profile['request']['completed'])
            ->toBeTrue()
            ->and($profile['request']['status'])
            ->toBe(302)
            ->and($profile['response_headers']['location'])
            ->toBe('/index.php?final=1')
            ->and($profile['response_headers']['x-toolbar-summary'])
            ->toBe($toolbar)
            ->and($requests)
            ->toHaveCount(1)
            ->and($requests[0])
            ->toBe([
                'method' => 'GET',
                'uri' => '/index.php?redirect=1',
                'auth' => 'guest',
            ]);
    });

    it('uses system TLS trust instead of the active gateway CA PEM', function (): void {
        if (! curlProfileOpenSslAvailable()) {
            $this->markTestSkipped('The openssl CLI is required for this TLS profile test.');
        }

        $server = startCurlProfileHttpsTestServer();

        config()->set('orbit.gateway.ca_pem_path', $server['ca_pem']);
        app()->forgetInstance(ProfileRequestProfiler::class);

        try {
            $profile = app(ProfileRequestProfiler::class)
                ->profile("https://localhost:{$server['port']}/");
        } finally {
            stopCurlProfileHttpsTestServer($server);
        }

        expect($profile['error'])
            ->toBeArray()
            ->and($profile['request']['completed'])
            ->toBeFalse()
            ->and($profile['request']['status'])
            ->toBeNull();
    });
})->group('slow');

/**
 * @return array{process: resource, directory: string, capture: string, port: int}
 */
function startSlowCurlProfileHttpTestServer(): array
{
    $directory = sys_get_temp_dir().'/orbit-curl-profile-slow-'.bin2hex(random_bytes(4));
    $capture = "{$directory}/requests.jsonl";

    if (! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        throw new RuntimeException("Unable to create test directory: {$directory}");
    }

    file_put_contents("{$directory}/index.php", <<<'PHP'
        <?php

        if (isset($_GET['redirect'])) {
            $capture = getenv('ORBIT_PROFILE_CAPTURE');

            if (is_string($capture) && $capture !== '') {
                file_put_contents($capture, json_encode([
                    'method' => $_SERVER['REQUEST_METHOD'] ?? null,
                    'uri' => $_SERVER['REQUEST_URI'] ?? null,
                    'auth' => $_SERVER['HTTP_X_TOOLBAR_AUTH'] ?? null,
                ], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND);
            }

            header('Location: /index.php?final=1', true, 302);
            header('X-Toolbar-Summary: eyJxdWVyaWVzIjp7ImNvdW50IjoxfX0=');
            echo 'redirect';

            return;
        }

        usleep(1_100_000);

        header('Content-Type: text/plain');

        echo 'profile-ok';
        PHP);

    $port = unusedCurlProfileTestPort();
    $process = proc_open(
        [
            PHP_BINARY,
            '-S',
            "127.0.0.1:{$port}",
            '-t',
            $directory,
        ],
        [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ],
        $pipes,
        $directory,
        ['ORBIT_PROFILE_CAPTURE' => $capture],
    );

    if (! is_resource($process)) {
        removeCurlProfileTestDirectory($directory);

        throw new RuntimeException('Unable to start slow profile test server.');
    }

    fclose($pipes[0]);

    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);

        if (is_resource($socket)) {
            fclose($socket);

            return [
                'process' => $process,
                'directory' => $directory,
                'capture' => $capture,
                'port' => $port,
            ];
        }

        usleep(10_000);
    }

    stopSlowCurlProfileHttpTestServer([
        'process' => $process,
        'directory' => $directory,
        'capture' => $capture,
        'port' => $port,
    ]);

    throw new RuntimeException('Slow profile test server did not become ready.');
}

/**
 * @param  array{process: resource, directory: string, capture: string, port: int}  $server
 */
function stopSlowCurlProfileHttpTestServer(array $server): void
{
    proc_terminate($server['process']);
    proc_close($server['process']);
    removeCurlProfileTestDirectory($server['directory']);
}

function curlProfileOpenSslAvailable(): bool
{
    $process = @proc_open(
        ['openssl', 'version'],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
    );

    if (! is_resource($process)) {
        return false;
    }

    foreach ($pipes as $pipe) {
        fclose($pipe);
    }

    return proc_close($process) === 0;
}

/**
 * @return array{process: resource, directory: string, ca_pem: string, port: int}
 */
function startCurlProfileHttpsTestServer(): array
{
    $directory = sys_get_temp_dir().'/orbit-curl-profile-'.bin2hex(random_bytes(4));

    if (! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        throw new RuntimeException("Unable to create test directory: {$directory}");
    }

    $caKey = "{$directory}/ca.key";
    $caPem = "{$directory}/ca.pem";
    $serverKey = "{$directory}/server.key";
    $serverCsr = "{$directory}/server.csr";
    $serverCert = "{$directory}/server.crt";
    $serverConfig = "{$directory}/server.cnf";
    $serverPem = "{$directory}/server.pem";
    $serverScript = "{$directory}/server.php";
    $readyFile = "{$directory}/ready";
    $errorFile = "{$directory}/server.err";

    file_put_contents($serverConfig, <<<'CONF'
        [req]
        distinguished_name = subject
        req_extensions = extensions
        prompt = no

        [subject]
        CN = localhost

        [extensions]
        subjectAltName = @alt_names

        [alt_names]
        DNS.1 = localhost
        IP.1 = 127.0.0.1
        CONF);

    runCurlProfileOpenSsl([
        'openssl',
        'req',
        '-x509',
        '-newkey',
        'rsa:2048',
        '-nodes',
        '-days',
        '1',
        '-subj',
        '/CN=Orbit Test Root CA',
        '-keyout',
        $caKey,
        '-out',
        $caPem,
    ], $directory);

    runCurlProfileOpenSsl([
        'openssl',
        'req',
        '-newkey',
        'rsa:2048',
        '-nodes',
        '-keyout',
        $serverKey,
        '-out',
        $serverCsr,
        '-config',
        $serverConfig,
    ], $directory);

    runCurlProfileOpenSsl([
        'openssl',
        'x509',
        '-req',
        '-in',
        $serverCsr,
        '-CA',
        $caPem,
        '-CAkey',
        $caKey,
        '-CAcreateserial',
        '-out',
        $serverCert,
        '-days',
        '1',
        '-extensions',
        'extensions',
        '-extfile',
        $serverConfig,
    ], $directory);

    $certificate = file_get_contents($serverCert);
    $privateKey = file_get_contents($serverKey);

    if (! is_string($certificate) || ! is_string($privateKey)) {
        removeCurlProfileTestDirectory($directory);

        throw new RuntimeException('Unable to read HTTPS profile test certificate.');
    }

    file_put_contents($serverPem, $certificate.PHP_EOL.$privateKey);
    file_put_contents($serverScript, <<<'PHP'
        <?php

        declare(strict_types=1);

        $port = (int) $argv[1];
        $certificate = (string) $argv[2];
        $readyFile = (string) $argv[3];

        $context = stream_context_create([
            'ssl' => [
                'allow_self_signed' => true,
                'local_cert' => $certificate,
                'verify_peer' => false,
            ],
        ]);

        $server = stream_socket_server(
            "tcp://127.0.0.1:{$port}",
            $errno,
            $error,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context,
        );

        if (! is_resource($server)) {
            fwrite(STDERR, "Unable to start TLS test server: {$error}\n");

            exit(1);
        }

        file_put_contents($readyFile, 'ready');

        while (true) {
            $client = @stream_socket_accept($server, 1);

            if (! is_resource($client)) {
                continue;
            }

            $tls = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER);

            if ($tls !== true) {
                fclose($client);

                continue;
            }

            stream_set_timeout($client, 2);

            $request = '';

            while (! feof($client)) {
                $line = fgets($client);

                if ($line === false) {
                    break;
                }

                $request .= $line;

                if (str_ends_with($request, "\r\n\r\n") || str_ends_with($request, "\n\n")) {
                    break;
                }
            }

            $body = 'profile-ok';

            fwrite($client, "HTTP/1.1 200 OK\r\n");
            fwrite($client, "Content-Type: text/plain\r\n");
            fwrite($client, 'Content-Length: '.strlen($body)."\r\n");
            fwrite($client, "Connection: close\r\n\r\n");
            fwrite($client, $body);

            @stream_socket_enable_crypto($client, false);
            fclose($client);
        }
        PHP);

    $port = unusedCurlProfileTestPort();
    $process = proc_open(
        [
            PHP_BINARY,
            $serverScript,
            (string) $port,
            $serverPem,
            $readyFile,
        ],
        [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', $errorFile, 'w'],
        ],
        $pipes,
        $directory,
    );

    if (! is_resource($process)) {
        removeCurlProfileTestDirectory($directory);

        throw new RuntimeException('Unable to start HTTPS profile test server.');
    }

    fclose($pipes[0]);

    for ($attempt = 0; $attempt < 50; $attempt++) {
        $status = proc_get_status($process);

        if (($status['running'] ?? false) !== true) {
            $error = is_file($errorFile) ? (string) file_get_contents($errorFile) : '';
            stopCurlProfileHttpsTestServer([
                'process' => $process,
                'directory' => $directory,
                'ca_pem' => $caPem,
                'port' => $port,
            ]);

            throw new RuntimeException('HTTPS profile test server exited early. '.$error);
        }

        if (is_file($readyFile)) {
            return [
                'process' => $process,
                'directory' => $directory,
                'ca_pem' => $caPem,
                'port' => $port,
            ];
        }

        usleep(10_000);
    }

    stopCurlProfileHttpsTestServer([
        'process' => $process,
        'directory' => $directory,
        'ca_pem' => $caPem,
        'port' => $port,
    ]);

    throw new RuntimeException('HTTPS profile test server did not become ready.');
}

/**
 * @param  list<string>  $command
 */
function runCurlProfileOpenSsl(array $command, string $directory): void
{
    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $directory,
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Unable to start OpenSSL.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        throw new RuntimeException("OpenSSL command failed: {$stderr}{$stdout}");
    }
}

function unusedCurlProfileTestPort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

    if (! is_resource($socket)) {
        throw new RuntimeException("Unable to allocate profile test port: {$error}");
    }

    $address = stream_socket_get_name($socket, false);

    fclose($socket);

    if (! is_string($address) || ! str_contains($address, ':')) {
        throw new RuntimeException('Unable to determine profile test port.');
    }

    return (int) substr(strrchr($address, ':'), 1);
}

/**
 * @param  array{process: resource, directory: string, ca_pem: string, port: int}  $server
 */
function stopCurlProfileHttpsTestServer(array $server): void
{
    proc_terminate($server['process']);
    proc_close($server['process']);
    removeCurlProfileTestDirectory($server['directory']);
}

function removeCurlProfileTestDirectory(string $directory): void
{
    $paths = glob($directory.'/*');

    if ($paths === false) {
        $paths = [];
    }

    foreach ($paths as $path) {
        @unlink($path);
    }

    @rmdir($directory);
}
