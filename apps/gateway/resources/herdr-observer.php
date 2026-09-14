<?php

declare(strict_types=1);

namespace Orbit\HerdrObserver;

use JsonException;
use RuntimeException;
use Throwable;

const MAX_HTTP_BYTES = 16_384;
const MAX_HERDR_LINE_BYTES = 8_388_608;
const MAX_NONCE_STORE_BYTES = 65_536;
const MAX_NONCE_STORE_ENTRIES = 1024;
const MAX_CLIENTS = 32;
const HANDSHAKE_TIMEOUT = 5;
const WRITE_TIMEOUT = 5;
const CHILD_STOP_TIMEOUT = 1;

final readonly class Configuration
{
    public function __construct(
        public string $listen,
        public string $node,
        public string $session,
        public string $jwks,
        public string $nonceStore,
        public string $herdr,
    ) {}

    /** @param list<string> $arguments */
    public static function fromArguments(array $arguments): self
    {
        $options = [];

        foreach (array_slice($arguments, 1) as $argument) {
            if (! str_starts_with($argument, '--') || ! str_contains($argument, '=')) {
                throw new RuntimeException('Invalid observer argument.');
            }

            [$name, $value] = explode('=', substr($argument, 2), 2);

            if ($name === '' || $value === '' || array_key_exists($name, $options)) {
                throw new RuntimeException('Invalid observer argument.');
            }

            $options[$name] = $value;
        }

        $expected = ['herdr', 'jwks', 'listen', 'node', 'nonce-store', 'session'];
        $names = array_keys($options);
        sort($names);

        if ($names !== $expected) {
            throw new RuntimeException('Incomplete observer configuration.');
        }

        if (! preg_match('/\A127\.0\.0\.1:[1-9][0-9]{0,4}\z/D', $options['listen'])) {
            throw new RuntimeException('Invalid observer listen address.');
        }

        if (! preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D', $options['node'])) {
            throw new RuntimeException('Invalid observer node.');
        }

        if (! preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D', $options['session'])) {
            throw new RuntimeException('Invalid observer session.');
        }

        foreach (['herdr', 'jwks', 'nonce-store'] as $path) {
            if (! str_starts_with($options[$path], '/')) {
                throw new RuntimeException('Observer paths must be absolute.');
            }
        }

        return new self(
            listen: $options['listen'],
            node: $options['node'],
            session: $options['session'],
            jwks: $options['jwks'],
            nonceStore: $options['nonce-store'],
            herdr: $options['herdr'],
        );
    }
}

final readonly class Claims
{
    public function __construct(
        public string $pane,
        public string $terminal,
        public int $columns,
        public int $rows,
        public string $nonce,
        public int $expiresAt,
        public string $origin,
    ) {}
}

final readonly class GrantVerifier
{
    public function __construct(private Configuration $configuration) {}

    public function verify(string $token, string $origin): Claims
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new RuntimeException('Grant rejected.');
        }

        [$headerPart, $payloadPart, $signaturePart] = $parts;
        $header = $this->object($headerPart);
        $payload = $this->object($payloadPart);

        if (($header['alg'] ?? null) !== 'RS256' || ! is_string($header['kid'] ?? null)) {
            throw new RuntimeException('Grant rejected.');
        }

        $jwk = $this->key($header['kid']);
        $signature = $this->base64Url($signaturePart);
        $verified = openssl_verify(
            "{$headerPart}.{$payloadPart}",
            $signature,
            $this->publicKey($jwk),
            OPENSSL_ALGO_SHA256,
        );

        if ($verified !== 1) {
            throw new RuntimeException('Grant rejected.');
        }

        $now = time();

        if (($payload['iss'] ?? null) !== 'orbit-gateway'
            || ($payload['aud'] ?? null) !== 'herdr-observe'
            || ($payload['sub'] ?? null) !== 'terminal.observe'
            || ($payload['node'] ?? null) !== $this->configuration->node
            || ($payload['session'] ?? null) !== $this->configuration->session) {
            throw new RuntimeException('Grant rejected.');
        }

        foreach (['pane', 'terminal', 'jti', 'origin'] as $field) {
            if (! is_string($payload[$field] ?? null) || $payload[$field] === '') {
                throw new RuntimeException('Grant rejected.');
            }
        }

        if (preg_match('/\A[A-Za-z0-9._:-]{1,64}\z/D', $payload['pane']) !== 1
            || preg_match('/\A[A-Za-z0-9._:-]{1,64}\z/D', $payload['terminal']) !== 1
            || preg_match('/\A[a-f0-9]{32}\z/D', $payload['jti']) !== 1) {
            throw new RuntimeException('Grant rejected.');
        }

        if (! hash_equals($payload['origin'], $origin)) {
            throw new RuntimeException('Origin rejected.');
        }

        foreach (['cols', 'rows', 'iat', 'exp'] as $field) {
            if (! is_int($payload[$field] ?? null)) {
                throw new RuntimeException('Grant rejected.');
            }
        }

        if ($payload['cols'] < 20 || $payload['cols'] > 400 || $payload['rows'] < 5 || $payload['rows'] > 200
            || $payload['iat'] > $now + 5 || $payload['exp'] <= $now || $payload['exp'] > $payload['iat'] + 60) {
            throw new RuntimeException('Grant rejected.');
        }

        return new Claims(
            pane: $payload['pane'],
            terminal: $payload['terminal'],
            columns: $payload['cols'],
            rows: $payload['rows'],
            nonce: $payload['jti'],
            expiresAt: $payload['exp'],
            origin: $payload['origin'],
        );
    }

    /** @return array<string, mixed> */
    private function object(string $encoded): array
    {
        try {
            $value = json_decode($this->base64Url($encoded), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Grant rejected.');
        }

        if (! is_array($value) || array_is_list($value)) {
            throw new RuntimeException('Grant rejected.');
        }

        return $value;
    }

    /** @return array<string, string> */
    private function key(string $keyId): array
    {
        $contents = @file_get_contents($this->configuration->jwks);

        if (! is_string($contents) || strlen($contents) > 65_536) {
            throw new RuntimeException('Signing keys unavailable.');
        }

        try {
            $jwks = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Signing keys unavailable.');
        }

        foreach (is_array($jwks['keys'] ?? null) ? $jwks['keys'] : [] as $key) {
            if (is_array($key) && ($key['kid'] ?? null) === $keyId && ($key['kty'] ?? null) === 'RSA'
                && ($key['use'] ?? null) === 'sig' && ($key['alg'] ?? null) === 'RS256'
                && is_string($key['n'] ?? null) && is_string($key['e'] ?? null)) {
                return $key;
            }
        }

        throw new RuntimeException('Signing key rejected.');
    }

    /** @param array<string, string> $jwk */
    private function publicKey(array $jwk): string
    {
        $modulus = $this->integer($this->base64Url($jwk['n']));
        $exponent = $this->integer($this->base64Url($jwk['e']));
        $rsa = $this->sequence($modulus.$exponent);
        $algorithm = hex2bin('300d06092a864886f70d0101010500');

        if (! is_string($algorithm)) {
            throw new RuntimeException('Signing key rejected.');
        }

        $der = $this->sequence($algorithm."\x03".$this->length(strlen($rsa) + 1)."\x00".$rsa);

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private function integer(string $value): string
    {
        $value = ltrim($value, "\x00");
        $value = $value === '' ? "\x00" : $value;

        if ((ord($value[0]) & 0x80) !== 0) {
            $value = "\x00".$value;
        }

        return "\x02".$this->length(strlen($value)).$value;
    }

    private function sequence(string $value): string
    {
        return "\x30".$this->length(strlen($value)).$value;
    }

    private function length(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    private function base64Url(string $value): string
    {
        if ($value === '' || preg_match('/\A[A-Za-z0-9_-]+\z/D', $value) !== 1) {
            throw new RuntimeException('Grant rejected.');
        }

        $standard = strtr($value, '-_', '+/');
        $remainder = strlen($standard) % 4;

        if ($remainder === 1) {
            throw new RuntimeException('Grant rejected.');
        }

        $standard .= str_repeat('=', (4 - $remainder) % 4);
        $decoded = base64_decode($standard, true);

        if (! is_string($decoded)) {
            throw new RuntimeException('Grant rejected.');
        }

        return $decoded;
    }
}

final readonly class NonceStore
{
    public function __construct(private string $path) {}

    public function consume(string $nonce, int $expiresAt): void
    {
        $stream = @fopen($this->path, 'c+');

        if ($stream === false || ! flock($stream, LOCK_EX)) {
            throw new RuntimeException('Replay protection unavailable.');
        }

        try {
            $metadata = fstat($stream);

            if (! is_array($metadata) || ! is_int($metadata['size'] ?? null)
                || $metadata['size'] > MAX_NONCE_STORE_BYTES) {
                throw new RuntimeException('Replay protection unavailable.');
            }

            $contents = stream_get_contents($stream);
            $entries = [];

            if (is_string($contents) && $contents !== '') {
                try {
                    $decoded = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw new RuntimeException('Replay protection unavailable.');
                }

                if (! is_array($decoded) || array_is_list($decoded)) {
                    throw new RuntimeException('Replay protection unavailable.');
                }

                foreach ($decoded as $key => $expiry) {
                    if (is_string($key) && is_int($expiry) && $expiry > time()) {
                        $entries[$key] = $expiry;
                    }
                }
            }

            if (array_key_exists($nonce, $entries)) {
                throw new RuntimeException('Grant already used.');
            }

            if (count($entries) >= MAX_NONCE_STORE_ENTRIES) {
                throw new RuntimeException('Replay protection capacity exceeded.');
            }

            $entries[$nonce] = $expiresAt;
            rewind($stream);

            if (! ftruncate($stream, 0)
                || fwrite($stream, json_encode($entries, JSON_THROW_ON_ERROR)) === false
                || ! fflush($stream)
                || ! fsync($stream)) {
                throw new RuntimeException('Replay protection unavailable.');
            }

            @chmod($this->path, 0600);
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
    }
}

final class ChildProcess
{
    /** @param resource $process @param array<int, resource> $pipes */
    public static function terminate($process, array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $status = proc_get_status($process);

        if (is_array($status) && ($status['running'] ?? false)) {
            proc_terminate($process);
            $status = self::waitUntilStopped($process);
        }

        if (is_array($status) && ($status['running'] ?? false)) {
            proc_terminate($process, 9);
            $status = self::waitUntilStopped($process);
        }

        if (! is_array($status) || ($status['running'] ?? false)) {
            throw new RuntimeException('Herdr child process could not be stopped.');
        }

        proc_close($process);
    }

    /** @param resource $process @return array<string, mixed>|false */
    private static function waitUntilStopped($process): array|false
    {
        $deadline = microtime(true) + CHILD_STOP_TIMEOUT;

        do {
            $status = proc_get_status($process);

            if (! is_array($status) || ! ($status['running'] ?? false)) {
                return $status;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        return proc_get_status($process);
    }
}

final readonly class Herdr
{
    public function __construct(private Configuration $configuration) {}

    public function assertTarget(Claims $claims): void
    {
        $result = $this->command([
            $this->configuration->herdr,
            '--session',
            $this->configuration->session,
            'api',
            'snapshot',
        ], 2);

        try {
            $response = json_decode($result, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Herdr snapshot unavailable.');
        }

        if (($response['result']['type'] ?? null) !== 'session_snapshot') {
            throw new RuntimeException('Herdr snapshot unavailable.');
        }

        $snapshot = is_array($response['result']['snapshot'] ?? null) ? $response['result']['snapshot'] : [];

        foreach (is_array($snapshot['panes'] ?? null) ? $snapshot['panes'] : [] as $pane) {
            if (is_array($pane) && ($pane['pane_id'] ?? null) === $claims->pane
                && ($pane['terminal_id'] ?? null) === $claims->terminal) {
                return;
            }
        }

        throw new RuntimeException('Herdr target unavailable.');
    }

    /** @return array{resource, array<int, resource>} */
    public function observe(Claims $claims): array
    {
        $pipes = [];
        $process = proc_open([
            $this->configuration->herdr,
            '--session',
            $this->configuration->session,
            'terminal',
            'session',
            'observe',
            $claims->terminal,
            '--cols',
            (string) $claims->columns,
            '--rows',
            (string) $claims->rows,
        ], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, null, ['bypass_shell' => true]);

        if (! is_resource($process) || count($pipes) !== 3) {
            if (is_resource($process)) {
                ChildProcess::terminate($process, $pipes);
            }

            throw new RuntimeException('Herdr observer unavailable.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [$process, $pipes];
    }

    /** @param list<string> $arguments */
    private function command(array $arguments, int $timeout): string
    {
        $pipes = [];
        $process = proc_open($arguments, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, null, ['bypass_shell' => true]);

        if (! is_resource($process) || count($pipes) !== 3) {
            if (is_resource($process)) {
                ChildProcess::terminate($process, $pipes);
            }

            throw new RuntimeException('Herdr unavailable.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $deadline = microtime(true) + $timeout;
        $stdout = '';

        try {
            while (microtime(true) < $deadline) {
                $stdout .= (string) fread($pipes[1], 65_536);
                fread($pipes[2], 65_536);

                if (strlen($stdout) > MAX_HERDR_LINE_BYTES) {
                    throw new RuntimeException('Herdr response too large.');
                }

                $status = proc_get_status($process);

                if (! is_array($status) || ! ($status['running'] ?? false)) {
                    return $stdout;
                }

                usleep(10_000);
            }

            throw new RuntimeException('Herdr snapshot timed out.');
        } finally {
            ChildProcess::terminate($process, $pipes);
        }
    }
}

final class WebSocket
{
    /** @param resource $stream */
    public static function accept($stream, PendingHandshake $handshake): void
    {
        $accept = base64_encode(sha1($handshake->key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        self::write($stream, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n");
        stream_set_blocking($stream, false);
    }

    /** @param resource $stream */
    public static function prepare($stream, Configuration $configuration): PendingHandshake
    {
        stream_set_timeout($stream, HANDSHAKE_TIMEOUT);
        $request = '';

        while (! str_contains($request, "\r\n\r\n")) {
            $chunk = fread($stream, 4096);

            if (! is_string($chunk) || $chunk === '') {
                throw new RuntimeException('Handshake unavailable.');
            }

            $request .= $chunk;

            if (strlen($request) > MAX_HTTP_BYTES) {
                throw new RuntimeException('Handshake too large.');
            }
        }

        [$head, $tail] = explode("\r\n\r\n", $request, 2);

        if ($tail !== '') {
            throw new RuntimeException('Handshake rejected.');
        }
        $lines = explode("\r\n", $head);
        $requestLine = array_shift($lines);

        if (! is_string($requestLine) || preg_match('/\AGET ([^ ]+) HTTP\/1\.1\z/D', $requestLine, $match) !== 1) {
            throw new RuntimeException('Handshake rejected.');
        }

        $headers = [];

        foreach ($lines as $line) {
            if (! str_contains($line, ':')) {
                throw new RuntimeException('Handshake rejected.');
            }

            [$name, $value] = explode(':', $line, 2);
            $name = strtolower(trim($name));

            if ($name === '' || array_key_exists($name, $headers)) {
                throw new RuntimeException('Handshake rejected.');
            }

            $headers[$name] = trim($value);
        }

        $upgrade = strtolower($headers['upgrade'] ?? '');
        $connection = strtolower($headers['connection'] ?? '');
        $key = $headers['sec-websocket-key'] ?? '';
        $decodedKey = base64_decode($key, true);

        if ($upgrade !== 'websocket' || ! in_array('upgrade', array_map('trim', explode(',', $connection)), true)
            || ($headers['sec-websocket-version'] ?? null) !== '13' || ! is_string($decodedKey) || strlen($decodedKey) !== 16) {
            throw new RuntimeException('Handshake rejected.');
        }

        $token = self::token($match[1]);
        $claims = (new GrantVerifier($configuration))->verify($token, $headers['origin'] ?? '');

        return new PendingHandshake($claims, $key);
    }

    /** @param resource $stream */
    public static function message($stream, string $payload): void
    {
        $length = strlen($payload);

        if ($length <= 125) {
            $header = chr(0x81).chr($length);
        } elseif ($length <= 65_535) {
            $header = chr(0x81).chr(126).pack('n', $length);
        } else {
            $header = chr(0x81).chr(127).pack('NN', 0, $length);
        }

        self::write($stream, $header.$payload);
    }

    /** @param resource $stream */
    public static function close($stream, int $code = 1000): void
    {
        try {
            self::write($stream, chr(0x88).chr(2).pack('n', $code));
        } catch (Throwable) {
        }
    }

    private static function token(string $target): string
    {
        $path = parse_url($target, PHP_URL_PATH);
        $query = parse_url($target, PHP_URL_QUERY);

        if (! in_array($path, ['', '/'], true) || ! is_string($query) || $query === '') {
            throw new RuntimeException('Grant missing.');
        }

        $pairs = explode('&', $query);

        if (count($pairs) !== 1 || ! str_starts_with($pairs[0], 'access_token=')) {
            throw new RuntimeException('Grant rejected.');
        }

        $token = rawurldecode(substr($pairs[0], strlen('access_token=')));

        if ($token === '') {
            throw new RuntimeException('Grant rejected.');
        }

        return $token;
    }

    /** @param resource $stream */
    private static function write($stream, string $bytes): void
    {
        $offset = 0;
        $deadline = microtime(true) + WRITE_TIMEOUT;

        while ($offset < strlen($bytes)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('WebSocket write timed out.');
            }

            $written = @fwrite($stream, substr($bytes, $offset));

            if ($written === false) {
                throw new RuntimeException('WebSocket write failed.');
            }

            if ($written === 0) {
                usleep(10_000);

                continue;
            }

            $offset += $written;
        }
    }
}

final readonly class PendingHandshake
{
    public function __construct(
        public Claims $claims,
        public string $key,
    ) {}
}

final readonly class Server
{
    public function __construct(private Configuration $configuration) {}

    public function run(): void
    {
        $server = @stream_socket_server('tcp://'.$this->configuration->listen, $errorNumber, $errorMessage);

        if ($server === false) {
            throw new RuntimeException($errorMessage !== '' ? $errorMessage : "Listen failed ({$errorNumber}).");
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static function (): void {
            exit(0);
        });
        pcntl_signal(SIGINT, static function (): void {
            exit(0);
        });
        $children = [];

        while (true) {
            while (($child = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
                unset($children[$child]);
            }

            $client = @stream_socket_accept($server, 1);

            if ($client === false) {
                continue;
            }

            if (count($children) >= MAX_CLIENTS) {
                self::httpError($client, 503, 'Service Unavailable');
                fclose($client);

                continue;
            }

            $processId = pcntl_fork();

            if ($processId === -1) {
                self::httpError($client, 503, 'Service Unavailable');
                fclose($client);

                continue;
            }

            if ($processId > 0) {
                $children[$processId] = true;
                fclose($client);

                continue;
            }

            fclose($server);
            $this->serve($client);
            fclose($client);
            exit(0);
        }
    }

    /** @param resource $client */
    private function serve($client): void
    {
        try {
            $handshake = WebSocket::prepare($client, $this->configuration);
            $herdr = new Herdr($this->configuration);
            $herdr->assertTarget($handshake->claims);
            (new NonceStore($this->configuration->nonceStore))->consume(
                $handshake->claims->nonce,
                $handshake->claims->expiresAt,
            );
            [$process, $pipes] = $herdr->observe($handshake->claims);
        } catch (Throwable) {
            self::httpError($client, 403, 'Forbidden');

            return;
        }

        try {
            WebSocket::accept($client, $handshake);
        } catch (Throwable) {
            ChildProcess::terminate($process, $pipes);

            return;
        }

        $buffer = '';
        $lastSequence = 0;

        try {
            while (true) {
                $read = [$client, $pipes[1], $pipes[2]];
                $write = null;
                $except = null;
                $ready = @stream_select($read, $write, $except, 15);

                if ($ready === false) {
                    throw new RuntimeException('Observer select failed.');
                }

                if ($ready === 0) {
                    continue;
                }

                if (in_array($client, $read, true)) {
                    $input = fread($client, 65_536);

                    if (! is_string($input) || $input === '') {
                        return;
                    }

                    WebSocket::close($client, 1008);

                    return;
                }

                if (in_array($pipes[2], $read, true)) {
                    fread($pipes[2], 65_536);
                }

                if (in_array($pipes[1], $read, true)) {
                    $chunk = fread($pipes[1], 65_536);

                    if (! is_string($chunk) || ($chunk === '' && feof($pipes[1]))) {
                        WebSocket::close($client, 1011);

                        return;
                    }

                    $buffer .= $chunk;

                    if (strlen($buffer) > MAX_HERDR_LINE_BYTES) {
                        throw new RuntimeException('Herdr frame too large.');
                    }

                    while (($newline = strpos($buffer, "\n")) !== false) {
                        $line = substr($buffer, 0, $newline);
                        $buffer = substr($buffer, $newline + 1);
                        [$lastSequence, $closed] = $this->forward(
                            $client,
                            $line,
                            $lastSequence,
                            $handshake->claims,
                        );

                        if ($closed) {
                            WebSocket::close($client);

                            return;
                        }
                    }
                }
            }
        } catch (Throwable) {
            WebSocket::close($client, 1011);
        } finally {
            ChildProcess::terminate($process, $pipes);
        }
    }

    /** @param resource $client */
    /** @return array{int, bool} */
    private function forward($client, string $line, int $lastSequence, Claims $claims): array
    {
        try {
            $message = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Invalid Herdr frame.');
        }

        if (! is_array($message) || ! in_array($message['type'] ?? null, ['terminal.frame', 'terminal.closed'], true)) {
            throw new RuntimeException('Unsupported Herdr frame.');
        }

        if (($message['type'] ?? null) === 'terminal.frame') {
            $bytes = $message['bytes'] ?? null;
            $sequence = $message['seq'] ?? null;

            if (($message['encoding'] ?? null) !== 'ansi' || ! is_bool($message['full'] ?? null)
                || ! is_string($bytes) || base64_decode($bytes, true) === false
                || ! is_int($sequence) || $sequence <= $lastSequence
                || ($message['width'] ?? null) !== $claims->columns
                || ($message['height'] ?? null) !== $claims->rows) {
                throw new RuntimeException('Invalid Herdr frame.');
            }

            $lastSequence = $sequence;
        }

        WebSocket::message($client, $line);

        return [$lastSequence, ($message['type'] ?? null) === 'terminal.closed'];
    }

    /** @param resource $stream */
    private static function httpError($stream, int $status, string $message): void
    {
        if (! is_resource($stream)) {
            return;
        }

        @fwrite($stream, "HTTP/1.1 {$status} {$message}\r\nConnection: close\r\nCache-Control: no-store\r\nContent-Length: 0\r\n\r\n");
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        (new Server(Configuration::fromArguments($argv)))->run();
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage()."\n");
        exit(1);
    }
}
