<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\AppDev\VitePortRuntime;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use RuntimeException;

final class FakeVitePortRuntime implements VitePortRuntime
{
    /** @var array<int, list<int>> */
    public array $occupied = [];

    public function selectPort(Node $node, int $preferred, array $excluded): int
    {
        for ($port = $preferred; $port <= 65535; $port++) {
            if (! in_array($port, [...$excluded, ...($this->occupied[$node->id] ?? [])], true)) {
                return $port;
            }
        }
        throw new RuntimeException('No test port is available.');
    }

    public function ownsListener(Process $process, AppInstance $instance, int $port): bool
    {
        return false;
    }

    public function ready(Process $process, AppInstance $instance, int $port): bool
    {
        return true;
    }

    public function suspendTraffic(AppInstance $instance): void {}

    public function prepare(Process $process, AppInstance $instance): void {}

    public function project(AppInstance $instance): void {}
}
