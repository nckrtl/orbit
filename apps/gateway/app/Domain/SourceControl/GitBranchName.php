<?php

declare(strict_types=1);

namespace App\Domain\SourceControl;

use InvalidArgumentException;

final class GitBranchName
{
    public static function validate(string $branch): string
    {
        if (! self::isValid($branch)) {
            throw new InvalidArgumentException('The Git branch name is invalid.');
        }

        return $branch;
    }

    public static function isValid(string $branch): bool
    {
        if ($branch === '' || strlen($branch) > 255 || $branch === 'HEAD' || str_starts_with($branch, '-')) {
            return false;
        }

        if (str_contains($branch, '..') || str_contains($branch, '@{') || str_ends_with($branch, '.')) {
            return false;
        }

        if (preg_match('/[\x00-\x20\x7F~^:?*\[\\\\]/', $branch) === 1) {
            return false;
        }

        return array_all(
            explode('/', $branch),
            static fn (string $component): bool => (
                $component !== ''
                && ! str_starts_with($component, '.')
                && ! str_ends_with($component, '.lock')
            ),
        );
    }
}
