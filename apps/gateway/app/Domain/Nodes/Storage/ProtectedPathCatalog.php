<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Storage;

use App\Domain\Nodes\ManagedUserAccount;

final readonly class ProtectedPathCatalog
{
    /** @var list<string> */
    private const array SYSTEM_ROOTS = ['/boot', '/dev', '/etc', '/proc', '/run', '/sys', '/usr'];

    /** @var list<string> */
    private const array ORBIT_ROOTS = ['/opt/orbit', '/var/lib/orbit', '/var/www'];

    public function isProtected(StoragePath $path, ManagedUserAccount $account): bool
    {
        $home = StoragePath::tryParse($account->home);

        if (! $home instanceof StoragePath) {
            return true;
        }

        if ($path->equals($home) || $home->isInside($path)) {
            return true;
        }

        foreach ([...self::SYSTEM_ROOTS, ...self::ORBIT_ROOTS] as $root) {
            $protected = StoragePath::tryParse($root);

            if ($protected instanceof StoragePath && $path->overlaps($protected)) {
                return true;
            }
        }

        return $this->isHiddenControlPath($path, $home);
    }

    public function worktreeDefault(ManagedUserAccount $account): ?StoragePath
    {
        $home = StoragePath::tryParse($account->home);

        if (! $home instanceof StoragePath) {
            return null;
        }

        return $home->append('.orbit', 'worktrees');
    }

    public function instanceDefault(ManagedUserAccount $account): ?StoragePath
    {
        $home = StoragePath::tryParse($account->home);

        if (! $home instanceof StoragePath) {
            return null;
        }

        return $home->append('apps');
    }

    private function isHiddenControlPath(StoragePath $path, StoragePath $home): bool
    {
        if (! $path->isInside($home)) {
            return false;
        }

        $relative = substr($path->value, strlen($home->value) + 1);
        $first = explode('/', $relative)[0];

        if (! str_starts_with($first, '.')) {
            return false;
        }

        $worktreeDefault = $home->append('.orbit', 'worktrees');

        return ! $path->equals($worktreeDefault) && ! $path->isInside($worktreeDefault);
    }
}
