<?php

declare(strict_types=1);

namespace App\Infrastructure\DatabaseServers;

use App\Domain\DatabaseServers\ValkeyAclUser;
use App\Domain\DatabaseServers\ValkeyServerAdmin;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseServer;
use Closure;
use Illuminate\Support\Sleep;
use Predis\Client;
use SensitiveParameter;
use Throwable;

/**
 * Runs Valkey admin commands with Predis over TCP to the Node's WireGuard address and the server's port, as `default`
 * with the admin password. Each call opens one connection and closes it. Error replies and connection failures never
 * reach a log: the ACL lines and Valkey's messages stay in this class, except a refused rule's message with every
 * hash masked.
 */
final readonly class PredisValkeyServerAdmin implements ValkeyServerAdmin
{
    private const float CONNECT_TIMEOUT_SECONDS = 5.0;

    private const float READ_WRITE_TIMEOUT_SECONDS = 10.0;

    private const int READY_POLL_SECONDS = 2;

    public function waitUntilReady(DatabaseServer $server, int $timeoutSeconds): bool
    {
        $deadline = now()->addSeconds($timeoutSeconds);

        while (! $this->acceptsAdminLogin($server)) {
            if (now()->greaterThanOrEqualTo($deadline)) {
                return false;
            }

            Sleep::for(self::READY_POLL_SECONDS)->seconds();
        }

        return true;
    }

    public function acceptsAdminLogin(DatabaseServer $server): bool
    {
        try {
            return $this->connected($server, true, function (Client $client): bool {
                $reply = $client->executeRaw(['PING'], $error);

                return $error === false && $reply === 'PONG';
            });
        } catch (Throwable) {
            return false;
        }
    }

    public function databaseCount(DatabaseServer $server): int
    {
        $reply = $this->connected($server, true, fn (Client $client): mixed => $this->command($server, $client, ['CONFIG', 'GET', 'databases']));
        $count = is_array($reply) ? ($reply[1] ?? null) : null;
        if (! is_string($count) || ! ctype_digit($count) || (int) $count < 1) {
            throw $this->failed($server);
        }

        return (int) $count;
    }

    public function userExists(DatabaseServer $server, string $username): bool
    {
        return $this->connected(
            $server,
            true,
            fn (Client $client): bool => $this->command($server, $client, ['ACL', 'GETUSER', $username]) !== null,
        );
    }

    public function setUser(
        DatabaseServer $server,
        string $username,
        #[SensitiveParameter]
        string $password,
        array $patterns,
        array $allow,
    ): string {
        return $this->connected($server, true, function (Client $client) use ($server, $username, $password, $patterns, $allow): string {
            $reply = $this->send($server, $client, [
                'ACL', 'SETUSER', $username,
                'reset', 'on', '#'.ValkeyAclUser::passwordHash($password), 'resetchannels',
                ...array_map(static fn (string $pattern): string => '~'.$pattern, $patterns),
                '-@all',
                ...array_map(static fn (string $rule): string => '+'.$rule, $allow),
                '-@admin', '-@dangerous',
            ], $error);

            if ($error === true) {
                throw new ResourceOperationException(
                    errorCode: 'database.acl_rules_invalid',
                    message: 'Valkey refused a key pattern or a rule: '.self::masked(is_string($reply) ? $reply : '').'.',
                    status: 422,
                );
            }

            try {
                $this->command($server, $client, ['ACL', 'SAVE']);
            } catch (ResourceOperationException $exception) {
                try {
                    $client->executeRaw(['ACL', 'DELUSER', $username]);
                } catch (Throwable) {
                    // The save failure is the error to report; the user stays only until Valkey restarts.
                }

                throw $exception;
            }

            return ($this->users($server, $client)[$username] ?? throw $this->failed($server))->rules;
        });
    }

    public function deleteUser(DatabaseServer $server, string $username): void
    {
        $this->connected($server, true, function (Client $client) use ($server, $username): void {
            $previous = $this->users($server, $client)[$username] ?? null;
            $this->command($server, $client, ['ACL', 'DELUSER', $username]);
            try {
                $this->command($server, $client, ['ACL', 'SAVE']);
            } catch (ResourceOperationException $exception) {
                if ($previous instanceof ValkeyAclUser) {
                    try {
                        $this->command($server, $client, ['ACL', 'SETUSER', $username, 'reset',
                            ...explode(' ', $previous->rules),
                            ...array_map(static fn (string $hash): string => '#'.$hash, $previous->passwordHashes),
                        ]);
                        $this->command($server, $client, ['ACL', 'SAVE']);
                    } catch (ResourceOperationException) {
                        // The record remains available for retry and Doctor reports any live drift.
                    }
                }
                throw $exception;
            }
        });
    }

    public function aclUsers(DatabaseServer $server): array
    {
        return $this->connected($server, true, fn (Client $client): array => $this->users($server, $client));
    }

    public function closeDefaultUser(DatabaseServer $server): void
    {
        $this->connected($server, true, function (Client $client) use ($server): void {
            $default = $this->users($server, $client)['default'] ?? throw $this->failed($server);
            if ($default->hasOnlyPassword($server->root_password)) {
                // A previous attempt may have lost its reply after SETUSER but before SAVE.
                $this->command($server, $client, ['ACL', 'SAVE']);

                return;
            }

            $ownId = $this->command($server, $client, ['CLIENT', 'ID']);
            if (! is_int($ownId) && ! is_string($ownId)) {
                throw $this->failed($server);
            }
            $ownId = (string) $ownId;
            $listing = $this->command($server, $client, ['CLIENT', 'LIST', 'USER', 'default']);
            if (! is_string($listing)) {
                throw $this->failed($server);
            }
            $addresses = [];
            foreach (explode("\n", trim($listing)) as $line) {
                $fields = [];
                foreach (explode(' ', $line) as $field) {
                    $pair = explode('=', $field, 2);
                    if (count($pair) === 2) {
                        $fields[$pair[0]] = $pair[1];
                    }
                }
                if (($fields['id'] ?? $ownId) !== $ownId) {
                    $address = $fields['addr'] ?? 'unknown';
                    $addresses[] = preg_match('/\A[0-9a-fA-F:.\[\]]+\z/', $address) === 1 ? $address : 'unknown';
                }
            }
            if ($addresses !== []) {
                throw new ResourceOperationException('database.server_in_use', count($addresses).' clients still use default: '.implode(', ', $addresses).'.', 409);
            }

            $this->command($server, $client, ['ACL', 'SETUSER', 'default', 'resetpass', '#'.ValkeyAclUser::passwordHash($server->root_password)]);
            try {
                $this->command($server, $client, ['ACL', 'SAVE']);
            } catch (ResourceOperationException $exception) {
                // Restore the previous password state on this still-authenticated connection.
                try {
                    $this->command($server, $client, ['ACL', 'SETUSER', 'default', 'resetpass',
                        ...(in_array('nopass', explode(' ', $default->rules), true) ? ['nopass'] : array_map(static fn (string $hash): string => '#'.$hash, $default->passwordHashes)),
                    ]);
                    $this->command($server, $client, ['ACL', 'SAVE']);
                } catch (ResourceOperationException) {
                    // Keep the server marked open so the next call checks and persists its live state.
                }
                throw $exception;
            }
        });
    }

    public function aclFile(DatabaseServer $server): ?string
    {
        $reply = $this->connected(
            $server,
            true,
            fn (Client $client): mixed => $this->command($server, $client, ['CONFIG', 'GET', 'aclfile']),
        );
        $file = is_array($reply) ? ($reply[1] ?? null) : null;

        return is_string($file) && $file !== '' ? $file : null;
    }

    public function acceptsAnonymous(DatabaseServer $server): bool
    {
        return $this->connected($server, false, function (Client $client) use ($server): bool {
            $reply = $this->send($server, $client, ['PING'], $error);

            return ! ($error === true && is_string($reply) && str_starts_with($reply, 'NOAUTH'));
        });
    }

    /** @return array<string, ValkeyAclUser> */
    private function users(DatabaseServer $server, Client $client): array
    {
        $lines = $this->command($server, $client, ['ACL', 'LIST']);
        $users = [];

        foreach (is_array($lines) ? $lines : throw $this->failed($server) as $line) {
            $user = is_string($line) ? ValkeyAclUser::fromAclListLine($line) : null;

            if (! $user instanceof ValkeyAclUser) {
                throw $this->failed($server);
            }

            $users[$user->name] = $user;
        }

        return $users;
    }

    /**
     * Send one command and fail on an error reply.
     *
     * @param  list<string>  $arguments
     */
    private function command(DatabaseServer $server, Client $client, array $arguments): mixed
    {
        $reply = $this->send($server, $client, $arguments, $error);

        if ($error === true) {
            throw $this->failed($server);
        }

        return $reply;
    }

    /**
     * Send one command. A connection failure throws; an error reply sets `$error` and returns Valkey's message.
     *
     * @param  list<string>  $arguments
     */
    private function send(DatabaseServer $server, Client $client, array $arguments, ?bool &$error = null): mixed
    {
        try {
            return $client->executeRaw($arguments, $error);
        } catch (Throwable) {
            throw $this->failed($server);
        }
    }

    /**
     * @template T
     *
     * @param  Closure(Client): T  $operation
     * @return T
     */
    private function connected(DatabaseServer $server, bool $authenticated, Closure $operation): mixed
    {
        $client = $this->client($server, $authenticated);

        try {
            return $operation($client);
        } finally {
            try {
                $client->disconnect();
            } catch (Throwable) {
                // The connection is gone either way.
            }
        }
    }

    private function client(DatabaseServer $server, bool $authenticated): Client
    {
        $host = $server->node->wireguard_ip;

        if (! is_string($host) || $host === '') {
            throw $this->failed($server);
        }

        return new Client([
            'scheme' => 'tcp',
            'host' => $host,
            'port' => $server->port,
            'timeout' => self::CONNECT_TIMEOUT_SECONDS,
            'read_write_timeout' => self::READ_WRITE_TIMEOUT_SECONDS,
            'protocol' => 2,
            'client_info' => false,
            ...($authenticated ? ['username' => 'default', 'password' => $server->root_password] : []),
        ]);
    }

    /** Valkey's message with every 64-character hex string masked, so no password hash reaches a response. */
    private static function masked(string $message): string
    {
        return rtrim((string) preg_replace('/[0-9A-Fa-f]{64}/', '[REDACTED]', $message), '.');
    }

    private function failed(DatabaseServer $server): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'database.server_command_failed',
            message: "Database server [{$server->slug}] could not run an admin command.",
            status: 502,
        );
    }
}
