<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerScopeLock;
use App\Domain\Tools\ToolManagerScopeLockException;
use App\Infrastructure\Nodes\NodeLocks;
use Closure;
use Illuminate\Contracts\Cache\Lock;

/**
 * Serializes each tool manager's shared state on one Node. The lock lives in the pinned NodeLocks store
 * with the operation term and is renewed before each command, so a process that dies mid-operation
 * blocks the manager for at most one term.
 */
final class NativeToolManagerScopeLock implements ToolManagerScopeLock
{
    /** @var array<string, array{lock: Lock, depth: positive-int}> */
    private array $held = [];

    public function __construct(private readonly ?NodeLocks $locks = null) {}

    public function run(int $nodeId, ToolManagerName $manager, Closure $callback): mixed
    {
        $key = "{$nodeId}:{$manager->value}";
        if (($this->held[$key] ?? null) !== null) {
            $this->held[$key]['depth']++;
            try {
                return $callback();
            } finally {
                $this->held[$key]['depth']--;
            }
        }

        $locks = $this->locks ?? app(NodeLocks::class);
        $lock = $locks->lock("tool-manager:{$nodeId}:{$manager->value}", $locks->operationSeconds());

        if (! $lock->get()) {
            throw new ToolManagerScopeLockException($nodeId, $manager);
        }

        $this->held[$key] = ['lock' => $lock, 'depth' => 1];

        try {
            return $callback();
        } finally {
            unset($this->held[$key]);
            $lock->release();
        }
    }
}
