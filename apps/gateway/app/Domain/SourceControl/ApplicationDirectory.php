<?php

declare(strict_types=1);

namespace App\Domain\SourceControl;

use App\Domain\Projects\ProjectApps;
use App\Domain\Shared\ResourceOperationException;

final class ApplicationDirectory
{
    public static function resolvePath(string $base, string $path): string
    {
        if (! ProjectApps::validPath($path, true)) {
            throw new ResourceOperationException('project.apps_invalid', 'The app path is not a contained relative directory.');
        }

        return $path === '.' ? rtrim($base, '/') : rtrim($base, '/').'/'.$path;
    }

    /** Legacy web-root derivation, retained only during the Gateway expand step. */
    public static function resolve(string $base, ?string $root): string
    {
        if ($root === 'public') {
            $root = null;
        } elseif (is_string($root) && str_ends_with($root, '/public')) {
            $root = substr($root, 0, -strlen('/public'));
        }

        return $root === null || $root === '.' || $root === ''
            ? rtrim($base, '/')
            : rtrim($base, '/').'/'.$root;
    }
}
