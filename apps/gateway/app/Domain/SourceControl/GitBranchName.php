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
        return (
            $branch !== ''
            && strlen($branch) <= 255
            && preg_match(
                '/\A(?!-)(?!.*(?:\.\.|@\{|[ ~^:?*\\\\]))(?!.*(?:\A|\/)\.)(?!.*\.lock(?:\/|\z))(?!.*\/\/)(?!.*[\/.]\z)[A-Za-z0-9._\/-]+\z/D',
                $branch,
            ) === 1
        );
    }
}
