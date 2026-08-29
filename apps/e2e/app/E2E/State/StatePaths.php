<?php

declare(strict_types=1);

namespace App\E2E\State;

use InvalidArgumentException;
use RuntimeException;

/** @mago-expect lint:cyclomatic-complexity Security validation requires explicit path checks. */
final readonly class StatePaths
{
    private string $root;

    public function __construct(string $root)
    {
        if ($root === '' || str_contains($root, "\0")) {
            throw new InvalidArgumentException('The state root is invalid.');
        }

        if (! is_dir($root) && ! mkdir($root, 0700, true) && ! is_dir($root)) {
            throw new RuntimeException('Cannot create the state directory.');
        }

        $resolved = realpath($root);

        if ($resolved === false || is_link($root)) {
            throw new RuntimeException('The state root must be a real directory.');
        }

        chmod($resolved, 0700);
        $this->root = rtrim($resolved, '/');
    }

    public static function fromEnvironment(?string $xdgStateHome = null, ?string $home = null): self
    {
        $base = $xdgStateHome ?? getenv('XDG_STATE_HOME') ?: null;
        $base ??= ($home ?? getenv('HOME') ?: sys_get_temp_dir()).'/.local/state';

        return new self(rtrim($base, '/').'/orbit/e2e');
    }

    public function root(): string
    {
        return $this->root;
    }

    public function path(string $relative): string
    {
        if (
            $relative === ''
            || str_starts_with($relative, '/')
            || str_contains($relative, "\0")
            || str_contains($relative, '\\')
        ) {
            throw new InvalidArgumentException('The state path is invalid.');
        }

        $parts = explode('/', $relative);

        if (in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)) {
            throw new InvalidArgumentException('The state path is invalid.');
        }

        $current = $this->root;

        foreach (array_slice($parts, 0, -1) as $part) {
            $current .= '/'.$part;

            if (! file_exists($current) && ! is_link($current)) {
                continue;
            }

            $resolved = realpath($current);

            if ($resolved === false || ! $this->isInsideRoot($resolved)) {
                throw new InvalidArgumentException('The state path escapes its root.');
            }
        }

        $path = $this->root.'/'.$relative;

        if (is_link($path)) {
            throw new InvalidArgumentException('The state path cannot be a symbolic link.');
        }

        return $path;
    }

    public function ensureParent(string $relative): string
    {
        $path = $this->path($relative);
        $parent = dirname($path);

        if (! is_dir($parent) && ! mkdir($parent, 0700, true) && ! is_dir($parent)) {
            throw new RuntimeException('Cannot create the state directory.');
        }

        $cursor = $parent;

        while ($this->isInsideRoot($cursor)) {
            if (is_link($cursor)) {
                throw new RuntimeException('A state directory cannot be a symbolic link.');
            }

            chmod($cursor, 0700);

            if ($cursor === $this->root) {
                break;
            }

            $cursor = dirname($cursor);
        }

        return $this->path($relative);
    }

    private function isInsideRoot(string $path): bool
    {
        return $path === $this->root || str_starts_with($path, $this->root.'/');
    }
}
