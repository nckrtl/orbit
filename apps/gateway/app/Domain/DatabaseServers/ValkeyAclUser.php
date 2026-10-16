<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use SensitiveParameter;

/**
 * One user of a Valkey Database server as `ACL LIST` reports it. `rules` is the line without `user <name>` and without
 * the password hashes, with single spaces, such as `on ~orbit-gateway:* resetchannels -@all +@connection`. Doctor
 * compares these strings exactly, and the password hashes separately.
 */
final readonly class ValkeyAclUser
{
    /** The admin user. It always has the Gateway's admin password, so no connection gets an ACL user of this name. */
    public const string ADMIN = 'default';

    /** @param list<string> $passwordHashes */
    public function __construct(
        public string $name,
        public string $rules,
        public array $passwordHashes,
    ) {}

    /** Parse one `ACL LIST` line, `user <name> <rule> ...`. Another line is null. */
    public static function fromAclListLine(string $line): ?self
    {
        $tokens = preg_split('/ +/', trim($line), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($tokens) < 2 || $tokens[0] !== 'user') {
            return null;
        }

        $rules = [];
        $hashes = [];

        foreach (array_slice($tokens, 2) as $token) {
            if (preg_match('/\A#([0-9a-f]{64})\z/D', $token, $hash) === 1) {
                $hashes[] = $hash[1];

                continue;
            }

            $rules[] = $token;
        }

        return new self($tokens[1], implode(' ', $rules), $hashes);
    }

    /** The form in which a password reaches Valkey and its ACL file: the lowercase SHA-256 hex digest. */
    public static function passwordHash(#[SensitiveParameter] string $password): string
    {
        return hash('sha256', $password);
    }

    /** Whether the user has exactly one password, and it is this one. */
    public function hasOnlyPassword(#[SensitiveParameter] string $password): bool
    {
        return $this->passwordHashes === [self::passwordHash($password)];
    }
}
