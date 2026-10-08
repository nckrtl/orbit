<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Tasks\TaskWorkspaceMcp;
use App\Infrastructure\Shared\StoredValue;
use App\Infrastructure\SourceControl\WorkspaceGit;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;
use Illuminate\Support\Facades\Log;

/**
 * Writes an untracked `.mcp.json` that points at this Gateway's `/mcp/search` endpoint and lists it in the checkout's
 * Git exclude file, so no commit ever includes it. The task agents read that file from their working
 * directory. `/mcp` still serves the full catalogue for other clients.
 */
final readonly class RemoteTaskWorkspaceMcp implements TaskWorkspaceMcp
{
    public function __construct(private TaskWorkspaceExecutor $ssh) {}

    public function installWhenMissing(Instance $instance): bool
    {
        return $this->place($instance);
    }

    private function place(Instance $instance): bool
    {
        $instance->loadMissing('node');

        if ($instance->checkout_path === '') {
            return false;
        }

        $gateway = $instance->task_sandbox_id !== null && $instance->project->slug === 'orbit'
            ? 'https://gateway.orbit'
            : rtrim(StoredValue::string(config('app.url')), '/');
        $config = json_encode(
            ['mcpServers' => ['orbit' => ['type' => 'http', 'url' => $gateway.'/mcp/search']]],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        );

        try {
            $result = $this->ssh->execute($instance, new RemoteCommand(
                arguments: ['bash', '-seu', '--', $instance->checkout_path],
                input: "checkout=\$1\n".WorkspaceGit::workerPreamble(TaskWorkerUser::name($instance)).TaskWorkspaceMetadata::bashPreamble().<<<'BASH'
                    status=0
                    workspace_git -C "$checkout" ls-files --error-unmatch -- .mcp.json >/dev/null 2>&1 || status=$?
                    if [ "$status" = 0 ]; then
                        printf 'tracked\n'
                        exit 0
                    fi
                    test "$status" = 1 || exit "$status"

                    BASH.TaskWorkspaceMetadata::operation('mcp', ['config' => $config]),
            ), 'task-workspace-mcp', 'tasks.workspace_mcp_failed');
        } catch (RuntimeConvergenceException) {
            return false;
        }

        if (trim($result->stdout) === 'tracked') {
            Log::info('The task workspace tracks its own .mcp.json; Orbit left it unchanged.', ['instance_id' => $instance->id]);
        }

        return in_array(trim($result->stdout), ['installed', 'tracked', 'present'], true);
    }
}
