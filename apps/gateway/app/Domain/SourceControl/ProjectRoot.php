<?php

declare(strict_types=1);

namespace App\Domain\SourceControl;

use App\Domain\Projects\ProjectType;
use InvalidArgumentException;

final class ProjectRoot
{
    public static function validate(string $root, ProjectType $type): string
    {
        if (! self::isValid($root, $type)) {
            throw new InvalidArgumentException('The Project root is invalid.');
        }

        return $root;
    }

    /** Why a root is invalid for a type: `.` is only a package root; anything else is not a normalized path. */
    public static function message(string $root, ProjectType $type): string
    {
        return $root === '.'
            ? "The root [.] is not valid for a {$type->value} Project. Send a web root such as public."
            : 'The root must be a normalized relative Project path.';
    }

    public static function isValid(string $root, ProjectType $type): bool
    {
        $packageRoot = $type === ProjectType::LaravelPackage || $type === ProjectType::NodePackage;

        return ($packageRoot && $root === '.') || RelativeWebRoot::isValid($root);
    }
}
