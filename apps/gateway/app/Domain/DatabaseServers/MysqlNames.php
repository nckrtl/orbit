<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

/**
 * Derives MySQL database and user names from Orbit names. Hyphens and other characters outside
 * `[a-z0-9_]` become underscores. A name over the MySQL limit keeps a prefix and ends in an
 * underscore and the first 8 characters of the SHA-1 of the full name, so it stays unique and
 * the same input always gives the same name.
 */
final readonly class MysqlNames
{
    public const int DATABASE_MAX_LENGTH = 64;

    public const int USER_MAX_LENGTH = 32;

    private const int HASH_LENGTH = 8;

    public function database(string $name): string
    {
        return $this->limit($this->normalize($name), self::DATABASE_MAX_LENGTH);
    }

    public function testDatabase(string $database): string
    {
        return $this->limit($database.'_test', self::DATABASE_MAX_LENGTH);
    }

    public function user(string $name): string
    {
        return $this->limit($this->normalize($name), self::USER_MAX_LENGTH);
    }

    public function instanceUser(string $projectSlug, string $instanceName): string
    {
        return $this->user($projectSlug.'_'.$instanceName);
    }

    private function normalize(string $name): string
    {
        return (string) preg_replace('/[^a-z0-9_]/', '_', strtolower($name));
    }

    private function limit(string $name, int $max): string
    {
        if (strlen($name) <= $max) {
            return $name;
        }

        $hash = substr(sha1($name), 0, self::HASH_LENGTH);

        return substr($name, 0, $max - self::HASH_LENGTH - 1).'_'.$hash;
    }
}
