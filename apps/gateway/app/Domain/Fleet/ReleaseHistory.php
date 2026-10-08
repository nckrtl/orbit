<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

/** The Gateway's own Git history, which maps its commit to a CLI release number. */
interface ReleaseHistory
{
    /** The full SHA of the commit that a hexadecimal revision names, or null when the history cannot resolve it. */
    public function commit(string $revision): ?string;

    /** `git rev-list --count` of the commit, or null when the history is shallow or cannot be read. */
    public function count(string $commit): ?int;

    /**
     * Up to `$limit` commits that the commit reaches, without the commit itself, newest first. Empty when the
     * history cannot be read.
     *
     * @return list<string>
     */
    public function ancestors(string $commit, int $limit): array;

    /**
     * Whether no file under the paths differs between two commits. False when the history cannot tell.
     *
     * @param  list<string>  $paths
     */
    public function unchanged(string $from, string $to, array $paths): bool;
}
