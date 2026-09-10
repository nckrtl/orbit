<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\TopologyRequest;
use App\E2E\Value\TopologyTarget;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Find the issue's registered Git worktree by branch or directory name.
 * An explicit path wins, including for retained evidence at another location.
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

        $process = new Process(['git', 'worktree', 'list', '--porcelain', '-z'], $this->primaryRoot);
        $process->mustRun();
        $name = strtolower($issue);
        $candidates = [];
        foreach (explode("\0\0", trim($process->getOutput(), "\0")) as $record) {
            $path = null;
            $branch = '';
            foreach (explode("\0", $record) as $field) {
                if (str_starts_with($field, 'worktree ')) {
                    $path = substr($field, strlen('worktree '));
                } elseif (str_starts_with($field, 'branch refs/heads/')) {
                    $branch = substr($field, strlen('branch refs/heads/'));
                }
            }
            if ($path !== null && is_dir($path)
                && ($branch === $name || str_starts_with($branch, $name.'-')
                    || basename($path) === $name || str_starts_with(basename($path), $name.'-'))) {
                $candidates[] = $path;
            }
        }
        if ($candidates === []) {
            throw new RuntimeException(
                "No registered worktree matches {$issue}; create one with bin/worktree-create or pass --worktree=.",
            );
        }
        if (count($candidates) > 1) {
            throw new RuntimeException(
                "More than one worktree matches {$issue}; pass --worktree= to choose: ".implode(', ', $candidates),
            );
        }

        return new TopologyRequest($issue, $candidates[0]);
    }
}
