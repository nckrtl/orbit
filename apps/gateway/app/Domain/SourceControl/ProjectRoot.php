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

    public static function isValid(string $root, ProjectType $type): bool
    {
        $packageRoot = $type === ProjectType::LaravelPackage || $type === ProjectType::NodePackage;

        return ($packageRoot && $root === '.') || RelativeWebRoot::isValid($root);
    }
}
