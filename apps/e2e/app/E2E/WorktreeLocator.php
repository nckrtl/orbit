<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\TopologyRequest;
use App\E2E\Value\TopologyTarget;
use RuntimeException;

/**
 * Find the one worktree of an issue: `<primary>/.worktrees/<issue-lowercase>-*`,
 * as `bin/worktree-create` names them. An explicit path wins.
 */
final readonly class WorktreeLocator
{
    public function __construct(
        private string $primaryRoot,
    ) {}

    public function locate(string $issue, ?string $explicit = null): TopologyRequest
    {
        TopologyTarget::assertIssue($issue);
        if ($explicit !== null && $explicit !== '') {
            return new TopologyRequest($issue, $explicit);
        }

        $pattern = $this->primaryRoot.'/.worktrees/'.strtolower($issue).'-*';
        $candidates = array_values(array_filter(glob($pattern, GLOB_ONLYDIR) ?: [], is_dir(...)));
        if ($candidates === []) {
            throw new RuntimeException(
                "No worktree matches {$pattern}; create one with bin/worktree-create or pass --worktree=.",
            );
        }
        if (count($candidates) > 1) {
            throw new RuntimeException(
                "More than one worktree matches {$pattern}; pass --worktree= to choose: ".implode(', ', $candidates),
            );
        }

        return new TopologyRequest($issue, $candidates[0]);
    }
}
