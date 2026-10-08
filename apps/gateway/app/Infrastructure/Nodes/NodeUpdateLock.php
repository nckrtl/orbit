<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Fleet\NodeShell;
use App\Infrastructure\Shared\StoredValue;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;
use Closure;
use Throwable;

/**
 * Holds the Node's update lock, `/run/lock/orbit-self-update.lock`, across several SSH commands. `orbit
 * self-update` holds the same lock while it replaces and health-checks the agent and the CLI, so a Gateway
 * converge that swaps or restarts the agent, or installs the CLI, never runs inside a self-update.
 *
 * One SSH command starts a transient unit that waits for the lock and then sleeps; the converge runs its own
 * commands while that unit holds the lock, and stopping the unit releases it. `RuntimeMaxSec` ends a hold that
 * a dead Gateway process left behind. The lock is re-entrant within one PHP process, so a converge inside a
 * held step does not wait for itself.
 */
final class NodeUpdateLock
{
    /** How long a converge waits for a self-update: its CLI download, checksum, and agent health window. */
    public const int WaitSeconds = 300;

    /** The longest a hold can last when its Gateway process dies without releasing it. */
    public const int HoldSeconds = 1800;

    public const string AcquireScript = <<<'BASH'
        unit=$1
        lock=$2
        wait=$3
        hold=$4
        marker="${5:-/run}/$unit.held"
        rm -f -- "$marker"
        systemd-run --quiet --unit="$unit" --collect --property=RuntimeMaxSec="$hold" \
          flock -w "$wait" "$lock" sh -c 'touch "$0"; exec sleep infinity' "$marker"
        deadline=$(( $(date +%s) + wait + 5 ))
        while [ ! -e "$marker" ]; do
          state=$(systemctl show -p ActiveState --value "$unit" 2>/dev/null || true)
          if [ "$state" != active ] && [ "$state" != activating ] && [ ! -e "$marker" ]; then
            exit 3
          fi
          if [ "$(date +%s)" -ge "$deadline" ]; then
            systemctl stop "$unit" >/dev/null 2>&1 || true
            exit 3
          fi
          sleep 0.5
        done
        BASH;

    /** @var array<string, int> Hold depth per Node, shared by every instance in this process. */
    private static array $held = [];

    public function __construct(private readonly NodeShell $shell) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     *
     * @throws ResourceOperationException `node.update_busy` when a self-update keeps the lock longer than the wait.
     */
    public function run(Node $node, Closure $operation): mixed
    {
        $key = $node->exists ? 'id:'.StoredValue::integer($node->getKey()) : 'name:'.$node->name;

        if ((self::$held[$key] ?? 0) > 0) {
            self::$held[$key]++;

            try {
                return $operation();
            } finally {
                self::$held[$key]--;
            }
        }

        $unit = 'orbit-update-lock-'.bin2hex(random_bytes(6));
        $acquired = $this->shell->run($node, new RemoteCommand(
            ['sudo', 'bash', '-seu', '--', $unit, NodeAgentFootprint::UpdateLockPath, (string) self::WaitSeconds, (string) self::HoldSeconds],
            input: self::AcquireScript,
            timeout: self::WaitSeconds + 60,
        ));

        if (! $acquired->succeeded()) {
            throw new ResourceOperationException('node.update_busy', "A self-update on node [{$node->name}] held its update lock for more than ".self::WaitSeconds.' seconds.', 409);
        }

        self::$held[$key] = 1;

        try {
            return $operation();
        } finally {
            unset(self::$held[$key]);

            try {
                $this->shell->run($node, new RemoteCommand(['sudo', 'sh', '-c', 'systemctl stop "$1" >/dev/null 2>&1; rm -f -- "/run/$1.held"', 'sh', $unit]));
            } catch (Throwable) {
                // RuntimeMaxSec ends the hold when the release cannot reach the Node.
            }
        }
    }
}
