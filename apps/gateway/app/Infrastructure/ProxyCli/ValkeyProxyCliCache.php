<?php

declare(strict_types=1);

namespace App\Infrastructure\ProxyCli;

use App\Domain\ProxyCli\ProxyCliCache;
use App\Models\DatabaseConnection;
use RuntimeException;

final readonly class ValkeyProxyCliCache implements ProxyCliCache
{
    public function __construct(
        private DatabaseConnection $connection,
    ) {}

    public function get(string $key): ?string
    {
        $reply = $this->command(['GET', $key]);

        return is_string($reply) ? $reply : null;
    }

    public function put(string $key, string $value, ?int $seconds = null): void
    {
        $seconds === null
            ? $this->command(['SET', $key, $value])
            : $this->command(['SET', $key, $value, 'EX', (string) $seconds]);
    }

    public function forget(string $key): void
    {
        $this->command(['DEL', $key]);
    }

    public function acquire(string $key, string $holder, int $seconds): bool
    {
        $reply = $this->command(['SET', $key, $holder, 'NX', 'EX', (string) $seconds]);

        return $reply === 'OK' || $this->get($key) === $holder;
    }

    public function release(string $key, string $holder): void
    {
        if ($this->get($key) === $holder) {
            $this->forget($key);
        }
    }

    /**
     * @param  list<string>  $arguments
     */
    private function command(array $arguments): mixed
    {
        $socket = @stream_socket_client(
            'tcp://'.$this->host().':'.$this->port(),
            $errorNumber,
            $errorMessage,
            3,
        );

        if (! is_resource($socket)) {
            throw new RuntimeException('Could not reach shared Valkey.');
        }

        stream_set_timeout($socket, 3);
        $this->authenticate($socket);
        fwrite($socket, $this->encode($arguments));
        $reply = $this->decode($socket);
        fclose($socket);

        return $reply;
    }

    /**
     * @param  resource  $socket
     */
    private function authenticate(mixed $socket): void
    {
        $password = $this->connection->password;

        if (! is_string($password) || $password === '') {
            return;
        }

        $username = $this->connection->username;
        $arguments = is_string($username) && $username !== ''
            ? ['AUTH', $username, $password]
            : ['AUTH', $password];
        fwrite($socket, $this->encode($arguments));
        $this->decode($socket);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function encode(array $arguments): string
    {
        $parts = ['*'.count($arguments)."\r\n"];

        foreach ($arguments as $argument) {
            $parts[] = '$'.strlen($argument)."\r\n".$argument."\r\n";
        }

        return implode('', $parts);
    }

    /**
     * @param  resource  $socket
     */
    private function decode(mixed $socket): mixed
    {
        $line = fgets($socket);

        if (! is_string($line) || $line === '') {
            return null;
        }

        $type = $line[0];
        $payload = substr($line, 1, -2);

        return match ($type) {
            '+' => $payload,
            '-' => throw new RuntimeException('Valkey error.'),
            ':' => (int) $payload,
            '$' => $this->bulk($socket, (int) $payload),
            default => null,
        };
    }

    /**
     * @param  resource  $socket
     */
    private function bulk(mixed $socket, int $length): ?string
    {
        if ($length < 0) {
            return null;
        }

        $value = stream_get_contents($socket, $length + 2);

        return is_string($value) ? substr($value, 0, $length) : null;
    }

    private function host(): string
    {
        return is_string($this->connection->host) && $this->connection->host !== ''
            ? $this->connection->host
            : '127.0.0.1';
    }

    private function port(): int
    {
        return $this->connection->port ?? 6379;
    }
}
