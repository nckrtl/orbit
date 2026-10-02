<?php

declare(strict_types=1);

namespace App\Domain\Tools;

/**
 * The shared Homebrew formula and cask token grammar.
 * A name may end with +, as in libsigc++ or logi-options+.
 */
final readonly class HomebrewPackageName
{
    public const int MAX_LENGTH = 255;

    private const string PATTERN = '/\A[a-z0-9](?:[a-z0-9@+._-]*[a-z0-9])?\+*\z/D';

    public static function valid(string $package): bool
    {
        return $package !== ''
            && strlen($package) <= self::MAX_LENGTH
            && preg_match(self::PATTERN, $package) === 1;
    }
}
