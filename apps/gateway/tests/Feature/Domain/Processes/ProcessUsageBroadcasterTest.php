<?php

declare(strict_types=1);

use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Processes\ProcessUsageBroadcaster;
use App\Domain\Processes\ProcessUsageIndex;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;

describe('Process usage broadcasts', function (): void {
    it('splits a sample into parts that stay under the Reverb message limit', function (): void {
        Event::fake([RecordBroadcast::class]);
        $node = Node::query()->create([
            'name' => 'usage-node', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
            'public_ssh_host' => '192.0.2.61', 'wireguard_ip' => '10.44.0.61',
        ]);
        foreach (range(1, 201) as $index) {
            Process::query()->create([
                'owner_type' => Node::class, 'owner_id' => $node->id, 'name' => "worker-{$index}", 'runtime' => 'systemd',
                'runtime_config' => ['command' => 'sleep infinity'], 'working_directory' => '/srv', 'restart_policy' => 'on-failure',
                'desired_state' => 'running', 'status' => 'active',
            ]);
        }
        $index = new class implements ProcessUsageIndex
        {
            public function usage(Collection $processes): array
            {
                return $processes->mapWithKeys(static fn ($process): array => [
                    (int) $process->id => $process->name === 'worker-7' ? ['cpu' => null, 'memory_bytes' => null] : ['cpu' => 123.456789, 'memory_bytes' => 987_654_321_000],
                ])->all();
            }
        };

        $parts = new ProcessUsageBroadcaster($index, app(RecordEventBroadcaster::class))->publish(1_790_000_000);

        $events = [];
        Event::assertDispatched(RecordBroadcast::class, function (RecordBroadcast $event) use (&$events): bool {
            $events[] = $event;

            return true;
        });
        $worker = Process::query()->where('name', 'worker-7')->firstOrFail();

        expect($parts)->toBe(2)
            ->and(array_map(static fn (RecordBroadcast $event): array => [$event->id, $event->data['part'], $event->data['parts'], count($event->data['processes'])], $events))
            ->toBe([[1_790_000_000, 1, 2, 200], [1_790_000_000, 2, 2, 1]])
            ->and($events[0]->data['processes'])->toContain([$worker->id, null, null]);

        foreach ($events as $event) {
            // The Pusher HTTP body carries the payload as a JSON string inside JSON.
            $body = json_encode(['name' => $event->broadcastAs(), 'channels' => ['private-orbit'], 'data' => json_encode($event->broadcastWith())]);
            expect(strlen((string) $body))->toBeLessThan(10_000);
        }
    });
});
