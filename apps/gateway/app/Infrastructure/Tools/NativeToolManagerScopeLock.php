<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerScopeLock;
use App\Domain\Tools\ToolManagerScopeLockException;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

final class NativeToolManagerScopeLock implements ToolManagerScopeLock
{
    /** @var array<string, array{lock: Lock, depth: positive-int}> */
    private array $held = [];

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

        $lock = Cache::lock("orbit:tool-manager:{$nodeId}:{$manager->value}", 3_600);

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
