<?php

declare(strict_types=1);

use App\Support\Realtime\RealtimeEvent;
use App\Support\Tui\State;

describe(State::class, function (): void {
    it('maps every loaded family into the row shape Screen renders', function (): void {
        $state = tui_test_state();

        expect($state->nodes)->toHaveCount(1)
            ->and($state->nodes[0]['name'])->toBe('beast')
            ->and($state->apps[0]['slug'])->toBe('charlie-shop')
            ->and($state->instances[0]['app']['slug'])->toBe('charlie-shop')
            ->and($state->instances[0]['node']['name'])->toBe('beast')
            ->and($state->processes[0]['name'])->toBe('horizon')
            ->and($state->schedules[0]['name'])->toBe('backup')
            ->and($state->firewall[0]['name'])->toBe('ssh')
            ->and($state->databases[0]['slug'])->toBe('charlie-shop');
    });

    it('flips a process row runtime_status when a process.status event arrives', function (): void {
        $state = tui_test_state();

        expect($state->processes[0]['runtime_status'])->toBe('running');

        $event = RealtimeEvent::fromChannelPayload('event', [
            'type' => 'process.status',
            'id' => 1,
            'at' => '2026-09-18T10:00:00+00:00',
            'data' => ['id' => 1, 'runtime_status' => 'stopped'],
        ]);

        $state->applyEvent($event);

        expect($state->processes[0]['runtime_status'])->toBe('stopped')
            // Fields the event did not carry stay as they were.
            ->and($state->processes[0]['name'])->toBe('horizon');
    });

    it('removes a row on a *.deleted event', function (): void {
        $state = tui_test_state();

        $event = RealtimeEvent::fromChannelPayload('event', [
            'type' => 'firewall.deleted',
            'id' => 2,
            'at' => '2026-09-18T10:00:00+00:00',
            'data' => ['id' => 1],
        ]);

        $state->applyEvent($event);

        expect($state->firewall)->toBeEmpty();
    });

    it('adds a new row on a *.created event for an unknown id', function (): void {
        $state = tui_test_state();

        $event = RealtimeEvent::fromChannelPayload('event', [
            'type' => 'node.created',
            'id' => 3,
            'at' => '2026-09-18T10:00:00+00:00',
            'data' => ['id' => 2, 'name' => 'shark', 'status' => 'provisioning', 'roles' => []],
        ]);

        $state->applyEvent($event);

        expect($state->nodes)->toHaveCount(2)
            ->and($state->nodes[1]['name'])->toBe('shark');
    });

    it('keeps a node.sample event out of the node collection and into nodeMetrics()', function (): void {
        $state = tui_test_state();

        expect($state->nodeMetrics(1))->toBeNull();

        $event = RealtimeEvent::fromChannelPayload('event', [
            'type' => 'node.sample',
            'id' => 4,
            'at' => '2026-09-18T10:00:00+00:00',
            'data' => [
                'node_id' => 1,
                'cores' => [0.1, 0.2],
                'mem' => [1.0, 8.0],
                'swap' => [0.0, 2.0],
                'uptime' => '3 days',
                'disks' => [['/', 10.0, 80.0]],
            ],
        ]);

        $state->applyEvent($event);

        expect($state->nodes[0]['status'])->toBe('active') // The node row itself did not change.
            ->and($state->nodeMetrics(1))->toBe([
                'cores' => [0.1, 0.2],
                'mem' => [1.0, 8.0],
                'swap' => [0.0, 2.0],
                'uptime' => '3 days',
                'disks' => [['/', 10.0, 80.0]],
            ]);
    });

    it('marks a family off-count when a row stops matching its desired state', function (): void {
        $state = tui_test_state();

        expect($state->counts()['Processes'])->toBe([1, 0]);

        $state->applyEvent(RealtimeEvent::fromChannelPayload('event', [
            'type' => 'process.status',
            'id' => 5,
            'at' => '2026-09-18T10:00:00+00:00',
            'data' => ['id' => 1, 'runtime_status' => 'stopped'],
        ]));

        expect($state->counts()['Processes'])->toBe([1, 1])
            ->and($state->attentionRows())->toHaveCount(1)
            ->and($state->attentionRows()[0]['label'])->toBe('Process');
    });
});
