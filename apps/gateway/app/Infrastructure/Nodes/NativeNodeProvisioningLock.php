<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Nodes\NodeProvisioningLock;
use App\Domain\Nodes\NodeProvisioningLockException;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

final class NativeNodeProvisioningLock implements NodeProvisioningLock
{
    /** @var array<string, array{lock: Lock, depth: positive-int}> */
    private array $held = [];

    public function run(string $nodeName, Closure $callback): mixed
    {
        if (($this->held[$nodeName] ?? null) !== null) {
            $this->held[$nodeName]['depth']++;
            try {
                return $callback();
            } finally {
                $this->held[$nodeName]['depth']--;
            }
        }

        $lock = Cache::lock("orbit:node-provision:{$nodeName}", 3_600);

        if (! $lock->get()) {
            throw new NodeProvisioningLockException($nodeName);
        }

        $this->held[$nodeName] = ['lock' => $lock, 'depth' => 1];

        try {
            return $callback();
        } finally {
            unset($this->held[$nodeName]);
            $lock->release();
        }
    }
}
