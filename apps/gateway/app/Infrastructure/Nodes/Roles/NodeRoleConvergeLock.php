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
    /**
     * How long one role operation may hold the lock. It matches the Gateway's PHP-FPM
     * `request_terminate_timeout`, so a worker killed at that limit leaves a lock that expires by the
     * time the request has failed.
     */
    public const int LockSeconds = 600;

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
     * @return T
     */
    public function run(Node $node, Closure $callback, string $errorCode = 'node_role.convergence_failed'): mixed
    {
        $name = 'node-role:'.($node->exists ? 'id:'.$node->getKey() : 'name:'.$node->name);

        if (isset($this->held[$name])) {
            $this->held[$name]['depth']++;

            try {
                return $callback();
            } finally {
                $this->held[$name]['depth']--;
            }
        }

        $lock = $this->locks->lock($name, self::LockSeconds);

        try {
            $lock->block($this->waitSeconds);
        } catch (LockTimeoutException $exception) {
            throw new NodeRoleOperationException(
                step: 'node-lock',
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
}
