<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\AppDev\VitePortRuntime;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use RuntimeException;

final class FakeVitePortRuntime implements VitePortRuntime
{
    /** @var array<int, list<int>> */
    public array $occupied = [];

    /** @var list<int> */
    public array $awakeInstances = [];

    /** @var list<string> */
    public array $events = [];

    public function selectPort(Node $node, int $preferred, array $excluded): int
    {
        for ($port = $preferred; $port <= 65535; $port++) {
            if (! in_array($port, [...$excluded, ...($this->occupied[$node->id] ?? [])], true)) {
                return $port;
            }
        }
        throw new RuntimeException('No test port is available.');
    }

    public function ownsListener(Process $process, Instance $instance, int $port): bool
    {
        return false;
    }

    public function ready(Process $process, Instance $instance, int $port): bool
    {
        $this->events[] = 'vite-ready';

        return true;
    }

    public function suspendTraffic(Instance $instance): void {}

    public function markAwake(Instance $instance): void
    {
        $this->awakeInstances[] = $instance->id;
    }

    public function prepare(Process $process, Instance $instance): void {}

    public function project(Instance $instance): void {}
}
