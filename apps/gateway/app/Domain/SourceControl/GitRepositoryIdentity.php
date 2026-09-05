<?php

declare(strict_types=1);

namespace App\Domain\SourceControl;

use InvalidArgumentException;
use SensitiveParameter;

final class GitRepositoryIdentity
{
    public static function derive(#[SensitiveParameter] string $repository): string
    {
        $repository = GitRepositoryOrigin::validate($repository);

        if (str_starts_with($repository, 'git@')) {
            $matches = [];

            if (preg_match('/\Agit@([^:]+):(.+)\z/u', $repository, $matches) !== 1) {
                throw new InvalidArgumentException('The Git repository origin is invalid.');
            }

            $host = $matches[1];
            $path = $matches[2];
        } else {
            $parts = parse_url($repository);

            if (! is_array($parts)) {
                throw new InvalidArgumentException('The Git repository origin is invalid.');
            }

            $host = $parts['host'] ?? null;
            $path = $parts['path'] ?? null;

            if (! is_string($host) || ! is_string($path)) {
                throw new InvalidArgumentException('The Git repository origin is invalid.');
            }
        }

        $path = ltrim($path, '/');

        if (str_ends_with($path, '.git')) {
            $path = substr($path, 0, -4);
        }

        return strtolower($host).'/'.$path;
    }
}
