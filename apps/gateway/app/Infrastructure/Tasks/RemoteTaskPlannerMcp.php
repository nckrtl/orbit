<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Tasks\TaskPlannerMcp;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;
use Illuminate\Support\Facades\Log;

/**
 * Writes an untracked `.mcp.json` that points at this Gateway's MCP server and lists it in the checkout's
 * Git exclude file, so no commit ever includes it. Agents read a project `.mcp.json` from their working directory.
 */
final readonly class RemoteTaskPlannerMcp implements TaskPlannerMcp
{
    public function __construct(private AppDevSshExecutor $ssh) {}

    public function install(AppInstance $instance): bool
    {
        $instance->loadMissing('node');

        if ($instance->checkout_path === '') {
            return false;
        }

        $config = json_encode(
            ['mcpServers' => ['orbit' => ['type' => 'http', 'url' => rtrim((string) config('app.url'), '/').'/mcp']]],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        );

        try {
            $result = $this->ssh->execute($instance->node, new RemoteCommand(
                arguments: ['bash', '-seu', '--', $instance->checkout_path, base64_encode($config)],
                input: <<<'BASH'
                    cd -- "$1"
                    if git ls-files --error-unmatch -- .mcp.json >/dev/null 2>&1; then
                        printf 'tracked\n'
                        exit 0
                    fi
                    printf '%s' "$2" | base64 -d > .mcp.json.orbit-new
                    printf '\n' >> .mcp.json.orbit-new
                    mv -f -- .mcp.json.orbit-new .mcp.json
                    exclude=$(git rev-parse --git-path info/exclude)
                    mkdir -p -- "$(dirname -- "$exclude")"
                    grep -qxF '/.mcp.json' "$exclude" 2>/dev/null || printf '/.mcp.json\n' >> "$exclude"
                    printf 'installed\n'
                    BASH,
            ), 'task-planner-mcp', 'tasks.planner_mcp_failed');
        } catch (RuntimeConvergenceException) {
            return false;
        }

        if (trim($result->stdout) === 'tracked') {
            Log::info('The task workspace tracks its own .mcp.json; Orbit left it unchanged.', ['app_instance_id' => $instance->id]);
        }

        return in_array(trim($result->stdout), ['installed', 'tracked'], true);
    }
}
