<?php

declare(strict_types=1);

namespace App\Domain\SourceControl;

final class ApplicationDirectory
{
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
