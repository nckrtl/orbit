<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\Tasks\TaskWorkspaceTopology;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;

/**
 * Runs the workspace's own `bin/e2e-topology` as the managed user. Acquiring changes host firewall rules, which the
 * task worker cannot do; agents only use the topology. A workspace without the harness has no topology.
 */
final readonly class RemoteTaskWorkspaceTopology implements TaskWorkspaceTopology
{
    private const string Script = <<<'BASH'
        cd -- "$1"
        test -x bin/e2e-topology || exit 0
        # `status` prints `absent` when the group holds no topology; older harnesses exit non-zero instead.
        state=$(bin/e2e-topology status "$2" 2>/dev/null | tail -n 1) || state=absent
        if [ "$3" = acquire ]; then
            case "$state" in absent | '') exec bin/e2e-topology acquire "$2" "$(pwd -P)" ;; esac
            exit 0
        fi
        case "$state" in absent | '') exit 0 ;; esac
        exec bin/e2e-topology release "$2"
        BASH;

    public function __construct(private DevelopmentSshExecutor $ssh) {}

    public function acquire(Instance $workspace, int $groupId): void
    {
        $this->run($workspace, $groupId, 'acquire');
    }

    public function release(Instance $workspace, int $groupId): void
    {
        $this->run($workspace, $groupId, 'release');
    }

    private function run(Instance $workspace, int $groupId, string $operation): void
    {
        $workspace->loadMissing('node');
        $this->ssh->execute(
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
