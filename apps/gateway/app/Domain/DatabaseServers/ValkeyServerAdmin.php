<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Models\DatabaseServer;
use SensitiveParameter;

/**
 * Runs admin commands on a Valkey Database server. The Gateway connects to the Node's WireGuard address and the
 * server's port, as every client does, and logs in as `default` with the admin password. A user's password reaches
 * Valkey only as its SHA-256 hash. A rule that Valkey refuses throws `database.acl_rules_invalid` with Valkey's message;
 * every other failure throws `database.server_command_failed` without output.
 */
interface ValkeyServerAdmin
{
    /** Wait until `default` can log in with the admin password. */
    public function waitUntilReady(DatabaseServer $server, int $timeoutSeconds): bool;

    /** Whether `default` logs in with the admin password now. A connection that fails is false. */
    public function acceptsAdminLogin(DatabaseServer $server): bool;

    /** The configured database count; valid indexes are zero through count minus one. */
    public function databaseCount(DatabaseServer $server): int;

    public function userExists(DatabaseServer $server, string $username): bool;

    /**
     * Run `ACL SETUSER <username> reset on #<hash> resetchannels ~<pattern> ... -@all +<rule> ... -@admin -@dangerous`
     * and `ACL SAVE`. When the save fails, it deletes the user again and fails.
     *
     * @param  list<string>  $patterns  Key patterns, without the `~`.
     * @param  list<string>  $allow  Command categories or commands, without the `+`.
     * @return string The user's rules as `ACL LIST` reports them, without the name and the password hash.
     */
    public function setUser(
        DatabaseServer $server,
        string $username,
        #[SensitiveParameter]
        string $password,
        array $patterns,
        array $allow,
    ): string;

    /** Run `ACL DELUSER` and `ACL SAVE`; restore the user on save failure. A missing user is fine. */
    public function deleteUser(DatabaseServer $server, string $username): void;

    /**
     * The live users, keyed by name.
     *
     * @return array<string, ValkeyAclUser>
     */
    public function aclUsers(DatabaseServer $server): array;

    /** Refuse other default clients, set its admin password, and persist it. */
    public function closeDefaultUser(DatabaseServer $server): void;

    /** The ACL file that Valkey runs with, from `CONFIG GET aclfile`, or null without one. */
    public function aclFile(DatabaseServer $server): ?string;

    /** Whether a client without a password can run a command. Only a `NOAUTH` reply to `PING` is false. */
    public function acceptsAnonymous(DatabaseServer $server): bool;
}
