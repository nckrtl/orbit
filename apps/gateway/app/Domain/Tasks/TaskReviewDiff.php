<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Instance;

/**
 * The tracked and untracked diff of a subtask since its start commit (ADR 0169).
 *
 * A no-op implementation returns no files. A read that cannot see the checkout, the base, or a
 * trustworthy summary throws TaskReviewDiffException. It does not look like an empty change.
 * When the captured output is cut, files_complete and diff_available are false and summary still
 * holds the full counts.
 */
interface TaskReviewDiff
{
    /**
     * @return array{
     *     files: list<array{path: string, insertions: int, deletions: int}>,
     *     diff: string,
     *     files_complete: bool,
     *     diff_available: bool,
     *     summary: array{files: int, insertions: int, deletions: int}
     * }
     *
     * @throws TaskReviewDiffException
     */
    public function read(Instance $instance, string $startCommit): array;
}
