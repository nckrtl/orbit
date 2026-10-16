<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\DatabaseServers\ValkeyAclUser;
use App\Domain\DatabaseServers\ValkeyServerAdmin;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseServer;
use Closure;
use SensitiveParameter;

/**
 * An in-memory Valkey server for tests. `$users` holds the live users by name, and `$passwords` the passwords that log
 * in as `default` at an address, keyed `<host>:<port>`. Every call is recorded in `$calls`, without a password.
 */
final class FakeValkeyServerAdmin implements ValkeyServerAdmin
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<string> */
    public array $readyChecks = [];

    /** @var list<bool> Readiness answers in order; once empty, every check succeeds. */
    public array $readiness = [];

    /** @var array<string, string> */
    public array $passwords = [];

    /** @var array<string, ValkeyAclUser> */
    public array $users = [];

    public ?string $aclFile = '/data/users.acl';

    public bool $anonymous = false;

    public int $databases = 16;

    /** @var list<string> */
    public array $defaultClients = [];

    /** A Valkey error reply that `setUser` returns, such as an unknown command. */
    public ?string $refuseRules = null;

    public bool $failCommands = false;

    /** Runs after `setUser` made the user, such as a concurrent create that takes the slug. */
    public ?Closure $afterSetUser = null;

    public function waitUntilReady(DatabaseServer $server, int $timeoutSeconds): bool
    {
        $this->readyChecks[] = $server->slug;

        return array_shift($this->readiness) ?? true;
    }

    public function acceptsAdminLogin(DatabaseServer $server): bool
    {
        $this->calls[] = "login {$server->node->wireguard_ip}:{$server->port}";

        return ($this->passwords["{$server->node->wireguard_ip}:{$server->port}"] ?? null) === $server->root_password;
    }

    public function databaseCount(DatabaseServer $server): int
    {
        $this->command($server, 'config get databases');

        return $this->databases;
    }

    public function userExists(DatabaseServer $server, string $username): bool
    {
        $this->command($server, "exists {$username}");

        return isset($this->users[$username]);
    }

    public function setUser(
        DatabaseServer $server,
        string $username,
        #[SensitiveParameter]
        string $password,
        array $patterns,
        array $allow,
    ): string {
        $this->command($server, "setuser {$username}");

        if ($this->refuseRules !== null) {
            throw new ResourceOperationException('database.acl_rules_invalid', "Valkey refused a key pattern or a rule: {$this->refuseRules}.", 422);
        }

        $rules = implode(' ', [
            'on',
            ...array_map(static fn (string $pattern): string => '~'.$pattern, $patterns),
            'resetchannels -@all',
            ...array_map(static fn (string $rule): string => '+'.$rule, $allow),
            '-@admin -@dangerous',
        ]);
        $this->users[$username] = new ValkeyAclUser($username, $rules, [ValkeyAclUser::passwordHash($password)]);

        if ($this->afterSetUser instanceof Closure) {
            ($this->afterSetUser)();
        }

        return $rules;
    }

    public function deleteUser(DatabaseServer $server, string $username): void
    {
        $this->command($server, "deluser {$username}");
        unset($this->users[$username]);
    }

    public function aclUsers(DatabaseServer $server): array
    {
        $this->command($server, 'acl list');

        return $this->users;
    }

    public function closeDefaultUser(DatabaseServer $server): void
    {
        $this->command($server, 'close default');
        if ($this->defaultClients !== []) {
            throw new ResourceOperationException('database.server_in_use', count($this->defaultClients).' clients still use default: '.implode(', ', $this->defaultClients).'.', 409);
        }
        $this->anonymous = false;
        $this->users['default'] = new ValkeyAclUser('default', 'on sanitize-payload ~* &* +@all', [ValkeyAclUser::passwordHash($server->root_password)]);
    }

    public function aclFile(DatabaseServer $server): ?string
    {
        $this->command($server, 'config get aclfile');

        return $this->aclFile;
    }

    public function acceptsAnonymous(DatabaseServer $server): bool
    {
        $this->command($server, 'anonymous ping');

        return $this->anonymous;
    }

    private function command(DatabaseServer $server, string $call): void
    {
        $this->calls[] = $call;

        if ($this->failCommands) {
            throw new ResourceOperationException(
                errorCode: 'database.server_command_failed',
                message: "Database server [{$server->slug}] could not run an admin command.",
                status: 502,
            );
        }
    }
}
