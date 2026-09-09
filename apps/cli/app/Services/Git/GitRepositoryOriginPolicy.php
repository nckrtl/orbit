<?php

declare(strict_types=1);

namespace App\Services\Git;

use SensitiveParameter;

/** @mago-expect lint:cyclomatic-complexity Each branch rejects one unsafe Git origin shape. */
final class GitRepositoryOriginPolicy
{
    public static function isSafe(#[SensitiveParameter] string $repository): bool
    {
        if (
            $repository === ''
            || preg_match('//u', $repository) !== 1
            || preg_match('/[\p{Z}\p{C}]/u', $repository) !== 0
        ) {
            return false;
        }

        if (preg_match('/\Agit@[^:\s?#]+:[^\s?#]+\z/u', $repository) === 1) {
            return true;
        }

        $parts = parse_url($repository);

        if (! is_array($parts)) {
            return false;
        }

        $scheme = is_string($parts['scheme'] ?? null) ? $parts['scheme'] : null;
        $host = is_string($parts['host'] ?? null) ? $parts['host'] : null;
        $path = is_string($parts['path'] ?? null) ? $parts['path'] : null;

        if (
            $host === null
            || $host === ''
            || $path === null
            || $path === ''
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
        ) {
            return false;
        }

        if ($scheme === 'https') {
            return ! array_key_exists('user', $parts) && ! array_key_exists('pass', $parts);
        }

        if ($scheme === 'ssh') {
            return ! array_key_exists('pass', $parts);
        }

        return false;
    }
}
