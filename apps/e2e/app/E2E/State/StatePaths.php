<?php

declare(strict_types=1);

namespace App\E2E\State;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

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

        $this->makePrivate($resolved);
        $this->root = rtrim($resolved, '/');
    }

    /** The primary checkout keeps the promoted topology snapshot generation and the host locks under `.e2e/`. */
    public static function forPrimary(string $repositoryRoot): self
    {
        return new self(rtrim($repositoryRoot, '/').'/.e2e');
    }

    /** A worktree keeps the state of its one issue attempt under `.e2e/`; it dies with the worktree. */
    public static function forWorktree(string $worktree): self
    {
        return new self(rtrim($worktree, '/').'/.e2e');
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

            $this->makePrivate($cursor);

            if ($cursor === $this->root) {
                break;
            }

            $cursor = dirname($cursor);
        }

        return $this->path($relative);
    }

    public function makeFilePrivate(string $relative): void
    {
        $this->makePrivate($this->path($relative), 0600);
    }

    /** Make a file that already lies inside the state root private. */
    public function makePathPrivate(string $path): void
    {
        if (! $this->isInsideRoot($path)) {
            throw new InvalidArgumentException('The state path is outside the state root.');
        }

        $this->makePrivate($path, 0600);
    }

    /**
     * Close state to the owning group and others. With named-user ACL entries, such as the task worker's, the mask is
     * recalculated from those entries: a mode such as 0600 would otherwise set the mask to none and cancel them.
     */
    private function makePrivate(string $path, int $mode = 0700): void
    {
        clearstatcache(true, $path);
        $acl = new Process(['getfacl', '--omit-header', '--numeric', '--no-effective', '--', $path]);
        $acl->mustRun();

        $entries = $acl->getOutput();

        if (preg_match('/^user:[^:]+:/m', $entries) === 1) {
            $owner = $mode === 0700 ? 'rwx' : 'rw-';
            $private = preg_match('/^user::'.preg_quote($owner, '/').'$/m', $entries) === 1
                && preg_match('/^group::---$/m', $entries) === 1
                && preg_match('/^other::---$/m', $entries) === 1
                && preg_match('/^mask::---$/m', $entries) !== 1;

            // Only the owner can change an ACL; another named user, such as the task worker, uses the path as is.
            if (! $private && fileowner($path) === posix_geteuid()) {
                // Without --no-mask, setfacl recalculates the mask as the union of the named entries.
                new Process(['setfacl', '--modify', 'user::'.$owner.',group::---,other::---', '--', $path])->mustRun();
            }

            return;
        }

        if ((fileperms($path) & 0777) !== $mode && ! chmod($path, $mode)) {
            throw new RuntimeException('Cannot make the state path private.');
        }
    }

    private function isInsideRoot(string $path): bool
    {
        return $path === $this->root || str_starts_with($path, $this->root.'/');
    }
}
