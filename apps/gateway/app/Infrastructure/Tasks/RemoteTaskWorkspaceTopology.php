<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Tasks\TaskWorkspaceTopology;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;
use RuntimeException;

/**
 * Runs the workspace's own `bin/e2e-topology` as the managed user. Acquiring changes host firewall rules, which the
 * task worker cannot do; agents only use the topology. A workspace without the harness has no topology.
 */
final readonly class RemoteTaskWorkspaceTopology implements TaskWorkspaceTopology
{
    private const string Script = <<<'BASH'
        cd -- "$1"
        test -x bin/e2e-topology || { echo orbit-topology-unavailable; exit 0; }
        if [ "$3" = acquire ]; then
            discovery_state() {
                local status
                status=$(bin/e2e-topology status "$2" --json) || { printf '%s\n' "$status"; return 1; }
                python3 -c '
        import json, sys
        status = json.loads(sys.argv[1])
        state = status.get("state", "")
        topology = status.get("discovery_topology") or status.get("topology")
        if state in ("absent", "captured"):
            print("absent")
        elif "discovery" in state.split("+") and isinstance(topology, dict) and topology.get("purpose") == "discovery":
            print("complete")
        else:
            print("incomplete")
        ' "$status"
            }
            state=$(discovery_state "$1" "$2") || { printf '%s\n' "$state"; exit 1; }
            if [ "$state" = complete ]; then
                echo orbit-topology-already-held
                exit 0
            fi
            if [ "$state" = absent ]; then
                bin/e2e-topology acquire "$2" "$(pwd -P)"
                state=$(discovery_state "$1" "$2") || { printf '%s\n' "$state"; exit 1; }
                if [ "$state" = complete ]; then
                    echo orbit-topology-ready
                    exit 0
                fi
            fi
            echo 'The topology has retained acquisition state without a complete discovery topology. State is kept for normal Orbit cleanup.' >&2
            exit 1
        fi
        # Release retains its existing ownership-checked cleanup, including incomplete acquisitions.
        state=$(bin/e2e-topology status "$2" 2>/dev/null | tail -n 1) || state=absent
        case "$state" in absent | '') exit 0 ;; esac
        exec bin/e2e-topology release "$2"
        BASH;

    public function __construct(private DevelopmentSshExecutor $ssh) {}

    public function acquire(Instance $workspace, int $groupId): bool
    {
        try {
            $result = $this->run($workspace, $groupId, 'acquire');
        } catch (RuntimeConvergenceException $exception) {
            // Laravel's harness error() writes to stdout; SSH and shell failures may use stderr.
            $reason = trim(($exception->result->stdout ?? '')."\n".($exception->result->stderr ?? ''));
            if ($reason === '') {
                throw $exception;
            }
            $reason = new CommandActivityInputSanitizer()->redactText($reason);

            throw new RuntimeException($exception->getMessage().' '.mb_substr($reason, -2000), previous: $exception);
        }
        $state = trim($result->stdout);
        if (str_ends_with($state, 'orbit-topology-ready')) {
            return true;
        }
        if (str_ends_with($state, 'orbit-topology-already-held')) {
            return false;
        }

        throw new RuntimeException('The workspace has no executable bin/e2e-topology harness.');
    }

    public function release(Instance $workspace, int $groupId): void
    {
        $this->run($workspace, $groupId, 'release');
    }

    private function run(Instance $workspace, int $groupId, string $operation): CommandResult
    {
        $workspace->loadMissing('node');

        return $this->ssh->execute(
            $workspace->node,
            new RemoteCommand(
                ['bash', '-ceu', self::Script, '--', $workspace->checkout_path, 'TASK-'.$groupId, $operation],
                maxOutputBytes: 16384,
                timeout: 900,
            ),
            'task-topology-'.$operation,
            'tasks.topology_failed',
            900,
            'Task topology',
        );
    }
}
