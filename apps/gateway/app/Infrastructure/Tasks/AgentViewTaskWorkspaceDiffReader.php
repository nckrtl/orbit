<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AgentView\AgentStateView;
use App\Domain\Tasks\TaskWorkspaceDiffReader;
use App\Models\AppInstance;

/**
 * Answers the task tick's commit check and the task diff from the Node agent's report of the checkout
 * while the Gateway's view of that Node is fresh, and runs `git` over SSH otherwise (ADR 0151).
 *
 * A report answers only for the `base` and `start` it was read against, so a changed default branch or
 * starting commit falls back to SSH until the agent reads the checkout again. A diff over the agent's
 * file limit is marked truncated, and its line counts come from SSH.
 */
final readonly class AgentViewTaskWorkspaceDiffReader implements TaskWorkspaceDiffReader
{
    public function __construct(
        private AgentStateView $view,
        private RemoteTaskWorkspaceDiffReader $remote,
    ) {}

    #[\Override]
    public function lineChanges(AppInstance $instance, string $baseBranch): ?array
    {
        $diff = $this->workspace($instance)['diff'] ?? null;

        if ($diff !== null && ! $diff['truncated'] && ($this->workspace($instance)['base'] ?? null) === $baseBranch && $baseBranch !== '') {
            return ['additions' => $diff['added'], 'deletions' => $diff['removed']];
        }

        return $this->remote->lineChanges($instance, $baseBranch);
    }

    #[\Override]
    public function lineDiff(AppInstance $instance, string $baseBranch): int
    {
        $changes = $this->lineChanges($instance, $baseBranch);

        return $changes === null ? 0 : $changes['additions'] + $changes['deletions'];
    }

    #[\Override]
    public function hasCommitsSince(AppInstance $instance, string $since): bool
    {
        $workspace = $this->workspace($instance);

        if ($workspace !== null && $workspace['commits'] !== null && $workspace['start'] === $since) {
            return $workspace['commits'] > 0;
        }

        return $this->remote->hasCommitsSince($instance, $since);
    }

    /** @return array{instance_id: int, base: string, start: ?string, branch: ?string, head: ?string, dirty: ?bool, commits: ?int, diff: array{files: int, added: int, removed: int, truncated: bool}|null}|null */
    private function workspace(AppInstance $instance): ?array
    {
        if ($instance->checkout_path === '') {
            return null;
        }

        return $this->view->node($instance->node_id)->workspace($instance->id);
    }
}
