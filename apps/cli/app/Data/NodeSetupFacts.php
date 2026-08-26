<?php

declare(strict_types=1);

namespace App\Data;

class NodeSetupFacts
{
    public function platform(): string
    {
        return strtolower(PHP_OS_FAMILY);
    }

    public function architecture(): string
    {
        return php_uname('m');
    }

    /** @return array{username: string, home_directory: string}|null */
    public function identity(): ?array
    {
        $uid = posix_geteuid();
        $identity = posix_getpwuid($uid);

        if (! is_array($identity)) {
            return null;
        }

        /** @var mixed $username */
        $username = $identity['name'] ?? null;
        /** @var mixed $homeDirectory */
        $homeDirectory = $identity['dir'] ?? null;

        if (
            ! is_string($username)
            || $username === ''
            || strlen($username) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $username) === 1
            || ! is_string($homeDirectory)
            || ! str_starts_with($homeDirectory, '/')
            || strlen($homeDirectory) > 4096
            || preg_match('/[\x00-\x1F\x7F]/', $homeDirectory) === 1
        ) {
            return null;
        }

        return [
            'username' => $username,
            'home_directory' => $homeDirectory,
        ];
    }
}
