<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Tasks\TaskPlannerMcp;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Shared\StoredValue;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;
use Illuminate\Support\Facades\Log;

/**
 * Writes an untracked `.mcp.json` that points at this Gateway's `/mcp/search` endpoint and lists it in the checkout's
 * Git exclude file, so no commit ever includes it. The planner and the reviewer read that file from their working
 * directory. `/mcp` still serves the full catalogue for other clients.
 */
final readonly class RemoteTaskPlannerMcp implements TaskPlannerMcp
{
    public function __construct(private AppDevSshExecutor $ssh) {}

    public function install(AppInstance $instance): bool
    {
        return $this->place($instance, onlyWhenMissing: false);
    }

    public function installWhenMissing(AppInstance $instance): bool
    {
        return $this->place($instance, onlyWhenMissing: true);
    }

    private function place(AppInstance $instance, bool $onlyWhenMissing): bool
    {
        $instance->loadMissing('node');

        if ($instance->checkout_path === '') {
            return false;
        }

        $config = json_encode(
            ['mcpServers' => ['orbit' => ['type' => 'http', 'url' => rtrim(StoredValue::string(config('app.url')), '/').'/mcp/search']]],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        );

        try {
            $result = $this->ssh->execute($instance->node, new RemoteCommand(
                arguments: [
                    'bash', '-seu', '--',
                    $instance->checkout_path,
                    base64_encode($config),
                    $onlyWhenMissing ? 'missing' : 'always',
                ],
                input: <<<'BASH'
                    cd -- "$1"
                    if git ls-files --error-unmatch -- .mcp.json >/dev/null 2>&1; then
                        printf 'tracked\n'
                        exit 0
                    fi
                    if [ "$3" = missing ] && [ -e .mcp.json ]; then
                        printf 'present\n'
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

        return in_array(trim($result->stdout), ['installed', 'tracked', 'present'], true);
    }
}
