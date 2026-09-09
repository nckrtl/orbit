<?php

declare(strict_types=1);

namespace App\Services\Git;

use SensitiveParameter;

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

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '';

        if ($host === '' || $path === '') {
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
