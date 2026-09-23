<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Tasks\TaskWorkspaceStateReader;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;
use JsonException;

final readonly class RemoteTaskWorkspaceStateReader implements TaskWorkspaceStateReader
{
    public function __construct(private AppDevSshExecutor $ssh) {}

    public function headCommit(AppInstance $instance): ?string
    {
        return $this->run($instance, 'git -C "$checkout" rev-parse HEAD');
    }

    public function currentBranch(AppInstance $instance): ?string
    {
        return $this->run($instance, 'git -C "$checkout" rev-parse --abbrev-ref HEAD');
    }

    public function isClean(AppInstance $instance): bool
    {
        return $this->run($instance, 'git -C "$checkout" status --porcelain --untracked-files=all') === '';
    }

    /**
     * Composer resolves an abbreviated command name, so `composer check` runs the built-in
     * `check-platform-reqs` command when the project defines no `check` script.
     */
    public function definesComposerCheckScript(AppInstance $instance): bool
    {
        $manifest = $this->run($instance, 'cat "$checkout/composer.json"');
        if ($manifest === null || $manifest === '') {
            return false;
        }
        try {
            $composer = json_decode($manifest, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }
        if (! is_array($composer) || ! is_array($composer['scripts'] ?? null)) {
            return false;
        }
        $check = $composer['scripts']['check'] ?? null;

        return (is_string($check) && trim($check) !== '') || (is_array($check) && $check !== []);
    }

    private function run(AppInstance $instance, string $command): ?string
    {
        $instance->loadMissing('node');
        if ($instance->checkout_path === '') {
            return null;
        }
        try {
            $result = $this->ssh->execute($instance->node, new RemoteCommand(
                arguments: ['bash', '-seu', '--', $instance->checkout_path],
                input: "checkout=\$1\n{$command}\n",
            ), 'task-workspace-state', 'tasks.diff_failed');
        } catch (RuntimeConvergenceException) {
            return null;
        }

        return trim($result->stdout);
    }
}
