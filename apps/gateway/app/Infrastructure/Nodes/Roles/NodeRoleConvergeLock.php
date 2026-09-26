<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Domain\Nodes\NodeRoleOperationException;
use App\Infrastructure\Nodes\NodeLocks;
use App\Models\Node;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;

/**
 * Runs one role convergence or removal per Node at a time, so two role operations never run
 * package, firewall, or service steps on the same machine together. The lock is re-entrant within
 * one PHP process, so a role operation that converges another role on the same Node keeps it.
 */
final class NodeRoleConvergeLock
{
    /** @var array<string, array{lock: Lock, depth: positive-int}> */
    private array $held = [];

    public function __construct(
        private readonly NodeLocks $locks,
        /** How long a role operation waits for another one on the same Node. */
        private readonly int $waitSeconds = 120,
    ) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @param  string  $errorCode  The operation's error code when the lock stays busy.
     * @param  string  $step  The operation's step name when the lock stays busy.
     * @return T
     */
    public function run(Node $node, Closure $callback, string $errorCode = 'node_role.convergence_failed', string $step = 'node-lock'): mixed
    {
        $name = self::name($node);

        if (isset($this->held[$name])) {
            $this->held[$name]['depth']++;

            try {
                return $callback();
            } finally {
                $this->held[$name]['depth']--;
            }
        }

        $lock = $this->locks->lock($name, $this->locks->operationSeconds());

        try {
            $lock->block($this->waitSeconds);
        } catch (LockTimeoutException $exception) {
            throw new NodeRoleOperationException(
                step: $step,
                errorCode: $errorCode,
                underlyingErrorCode: 'node_role.node_busy',
                message: "Another role operation is still running on node [{$node->name}].",
                previous: $exception,
            );
        }

        $this->held[$name] = ['lock' => $lock, 'depth' => 1];

        try {
            return $callback();
        } finally {
            unset($this->held[$name]);
            $lock->release();
        }
    }

    /** Whether any process holds the Node's role lock now. */
    public function isHeld(Node $node): bool
    {
        return $this->locks->lock(self::name($node), 1)->isLocked();
    }

    private static function name(Node $node): string
    {
        return 'node-role:'.($node->exists ? 'id:'.$node->getKey() : 'name:'.$node->name);
    }
}
