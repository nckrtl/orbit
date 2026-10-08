<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway;

use Closure;
use RuntimeException;

/**
 * One FastCGI request to a PHP-FPM socket for a script outside the web root, so code runs inside the pool itself,
 * for example to reset the pool's OPcache. The socket is reachable only by the Gateway account and Caddy.
 */
final readonly class FpmScriptRequest
{
    private const int Version = 1;

    private const int BeginRequest = 1;

    private const int EndRequest = 3;

    private const int Params = 4;

    private const int Stdin = 5;

    private const int Stdout = 6;

    private const int Stderr = 7;

    private const int Responder = 1;

    /** @var Closure(): resource */
    private Closure $connect;

    /** @param (Closure(): resource)|null $connect opens the stream to the pool */
    public function __construct(
        private string $socket = '/run/php/orbit-gateway.sock',
        private float $timeout = 10.0,
        ?Closure $connect = null,
    ) {
        $this->connect = $connect ?? function () {
            $stream = @stream_socket_client('unix://'.$this->socket, $code, $message, $this->timeout);

            if ($stream === false) {
                throw new RuntimeException("The PHP-FPM socket [{$this->socket}] cannot be reached: {$message}");
            }

            return $stream;
        };
    }

    /** @return string the response body */
    public function request(string $script, string $query = ''): string
    {
        $stream = ($this->connect)();
        stream_set_timeout($stream, (int) ceil($this->timeout));

        try {
            $params = [
                'GATEWAY_INTERFACE' => 'FastCGI/1.0',
                'REQUEST_METHOD' => 'GET',
                'SCRIPT_FILENAME' => $script,
                'SCRIPT_NAME' => '/'.basename($script),
                'QUERY_STRING' => $query,
                'REQUEST_URI' => '/'.basename($script).($query === '' ? '' : '?'.$query),
                'SERVER_PROTOCOL' => 'HTTP/1.1',
                'REMOTE_ADDR' => '127.0.0.1',
                'CONTENT_LENGTH' => '0',
            ];
            $encoded = '';

            foreach ($params as $name => $value) {
                $encoded .= $this->length(strlen($name)).$this->length(strlen($value)).$name.$value;
            }

            fwrite($stream, $this->record(self::BeginRequest, pack('nCx5', self::Responder, 0))
                .$this->record(self::Params, $encoded)
                .$this->record(self::Params, '')
                .$this->record(self::Stdin, ''));

            return $this->response($stream);
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream */
    private function response($stream): string
    {
        $output = '';
        $errors = '';

        while (true) {
            $header = $this->read($stream, 8);
            $fields = unpack('Cversion/Ctype/nid/nlength/Cpadding', $header);

            if (! is_array($fields)) {
                throw new RuntimeException('PHP-FPM sent a malformed FastCGI record.');
            }

            $content = $this->read($stream, (int) $fields['length']);
            $this->read($stream, (int) $fields['padding']);

            match ((int) $fields['type']) {
                self::Stdout => $output .= $content,
                self::Stderr => $errors .= $content,
                default => null,
            };

            if ((int) $fields['type'] === self::EndRequest) {
                break;
            }
        }

        $parts = explode("\r\n\r\n", $output, 2);
        $headers = $parts[0];
        $body = $parts[1] ?? '';

        if (preg_match('/^Status:\s*(\d{3})/mi', $headers, $status) === 1 && (int) $status[1] >= 400) {
            throw new RuntimeException("PHP-FPM answered {$status[1]}: ".trim($errors.' '.$body));
        }

        return $body;
    }

    /** @param resource $stream */
    private function read($stream, int $length): string
    {
        $data = '';

        while (($missing = $length - strlen($data)) > 0) {
            $chunk = fread($stream, $missing);

            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('PHP-FPM closed the connection or did not answer in time.');
            }

            $data .= $chunk;
        }

        return $data;
    }

    private function record(int $type, string $content): string
    {
        return pack('CCnnCx', self::Version, $type, 1, strlen($content), 0).$content;
    }

    /** A FastCGI name-value length: one byte below 128, else four bytes with the high bit set. */
    private function length(int $length): string
    {
        return $length < 128 ? pack('C', $length) : pack('N', $length | 0x80000000);
    }
}
