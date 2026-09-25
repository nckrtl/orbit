<?php

declare(strict_types=1);

use App\Infrastructure\AgentView\AgentChannelState;

/** @return array{name: string, runtime: string, runtime_status: string} */
function channel_unit(string $name, string $status, string $runtime = 'systemd'): array
{
    return ['name' => $name, 'runtime' => $runtime, 'runtime_status' => $status];
}

describe('AgentChannelState', function (): void {
    it('applies a snapshot only once every part has arrived in order', function (): void {
        $state = new AgentChannelState;

        $state->apply('client-snapshot', ['sequence' => 4, 'part' => 1, 'parts' => 2, 'docker' => 'available', 'units' => [
            channel_unit('orbit-process-1-web', 'active'),
        ]], 100.0);

        expect($state->hasSnapshot)->toBeFalse();

        $state->apply('client-snapshot', ['sequence' => 5, 'part' => 2, 'parts' => 2, 'docker' => 'available', 'units' => [
            channel_unit('orbit-process-2-cache', 'running', 'docker'),
        ]], 100.5);

        expect($state->hasSnapshot)->toBeTrue()
            ->and($state->docker)->toBe('available')
            ->and($state->units)->toBe([
                'systemd:orbit-process-1-web' => 'active',
                'docker:orbit-process-2-cache' => 'running',
            ])
            ->and($state->lastEventAt)->toBe(100.5);
    });

    it('drops a snapshot whose parts arrive out of order', function (): void {
        $state = new AgentChannelState;

        $state->apply('client-snapshot', ['sequence' => 4, 'part' => 1, 'parts' => 2, 'docker' => 'absent', 'units' => []], 100.0);
        $state->apply('client-snapshot', ['sequence' => 7, 'part' => 2, 'parts' => 2, 'docker' => 'absent', 'units' => []], 100.0);

        expect($state->hasSnapshot)->toBeFalse();
    });

    it('updates one unit from a process change and refreshes the event time on a heartbeat', function (): void {
        $state = new AgentChannelState;
        $state->apply('client-snapshot', ['sequence' => 1, 'part' => 1, 'parts' => 1, 'docker' => 'available', 'units' => [
            channel_unit('orbit-process-1-web', 'inactive'),
        ]], 100.0);

        $state->apply('client-process', ['sequence' => 2, 'unit' => channel_unit('orbit-process-1-web', 'active')], 101.0);
        $state->apply('client-heartbeat', ['sequence' => 3, 'at' => '2026-09-25T10:00:00Z'], 105.0);

        expect($state->units)->toBe(['systemd:orbit-process-1-web' => 'active'])
            ->and($state->lastEventAt)->toBe(105.0)
            ->and($state->agentAt)->toBe('2026-09-25T10:00:00Z');
    });

    it('starts over when the agent sequence restarts', function (): void {
        $state = new AgentChannelState;
        $state->apply('client-snapshot', ['sequence' => 9, 'part' => 1, 'parts' => 1, 'docker' => 'available', 'units' => [
            channel_unit('orbit-process-1-web', 'active'),
        ]], 100.0);

        $state->apply('client-heartbeat', ['sequence' => 1], 110.0);

        expect($state->hasSnapshot)->toBeFalse()
            ->and($state->units)->toBe([]);
    });

    it('wants a snapshot while agent events arrive without a complete one', function (): void {
        $state = new AgentChannelState;

        $state->apply('client-heartbeat', ['sequence' => 7], 100.0);
        $state->apply('client-heartbeat', ['sequence' => 8], 105.0);

        expect($state->snapshotWantedSince)->toBe(100.0);

        $state->apply('client-snapshot', ['sequence' => 9, 'part' => 1, 'parts' => 1, 'docker' => 'absent', 'units' => []], 106.0);

        expect($state->snapshotWantedSince)->toBeNull();
    });

    it('wants a confirming snapshot after its sequence goes back, even when the other stream completes one', function (): void {
        $state = new AgentChannelState;
        $state->apply('client-snapshot', ['sequence' => 40, 'part' => 1, 'parts' => 1, 'docker' => 'available', 'units' => [
            channel_unit('orbit-process-1-web', 'running', 'docker'),
        ]], 100.0);

        // A second connection that publishes as the same agent member starts again at 1.
        $state->apply('client-snapshot', ['sequence' => 1, 'part' => 1, 'parts' => 1, 'docker' => 'absent', 'units' => []], 101.0);

        expect($state->hasSnapshot)->toBeTrue()
            ->and($state->units)->toBe([])
            ->and($state->snapshotWantedSince)->toBe(101.0);

        $state->snapshotRequested();
        $state->apply('client-snapshot', ['sequence' => 41, 'part' => 1, 'parts' => 1, 'docker' => 'available', 'units' => [
            channel_unit('orbit-process-1-web', 'running', 'docker'),
        ]], 106.0);

        expect($state->units)->toBe(['docker:orbit-process-1-web' => 'running'])
            ->and($state->snapshotWantedSince)->toBeNull();
    });

    it('keeps only well-formed Orbit Process units', function (): void {
        $state = new AgentChannelState;

        $state->apply('client-snapshot', ['sequence' => 1, 'part' => 1, 'parts' => 1, 'docker' => 'available', 'units' => [
            channel_unit('orbit-process-1-web', 'active'),
            channel_unit('sshd', 'active'),
            channel_unit('orbit-process-2-web', 'active; rm -rf /'),
            channel_unit('orbit-process-3-web', 'running', 'podman'),
            'not-a-unit',
        ]], 100.0);

        expect($state->units)->toBe(['systemd:orbit-process-1-web' => 'active']);
    });

    it('ignores an event without a positive integer sequence', function (): void {
        $state = new AgentChannelState;

        expect($state->apply('client-heartbeat', ['sequence' => '1'], 100.0))->toBeFalse()
            ->and($state->lastEventAt)->toBeNull();
    });

    it('keeps at most the unit cap for one Node', function (): void {
        $units = array_map(
            static fn (int $id): array => channel_unit("orbit-process-{$id}-web", 'active'),
            range(1, AgentChannelState::MaxUnits + 10),
        );
        $state = new AgentChannelState;

        $state->apply('client-snapshot', ['sequence' => 1, 'part' => 1, 'parts' => 1, 'docker' => 'available', 'units' => $units], 100.0);

        expect($state->units)->toHaveCount(AgentChannelState::MaxUnits);
    });

    it('replaces the workspaces only when every part of a list has arrived in order', function (): void {
        $state = new AgentChannelState;
        $workspace = static fn (int $id, string $head): array => [
            'instance_id' => $id, 'base' => 'main', 'start' => null, 'branch' => null, 'head' => str_repeat($head, 40),
            'dirty' => null, 'commits' => null, 'diff' => null,
        ];

        $state->apply('client-workspaces', ['sequence' => 1, 'part' => 1, 'parts' => 2, 'workspaces' => [$workspace(1, 'a')]], 100.0);
        expect($state->workspaces)->toBe([]);

        $state->apply('client-workspaces', ['sequence' => 3, 'part' => 2, 'parts' => 2, 'workspaces' => [$workspace(2, 'b')]], 100.0);
        expect($state->workspaces)->toBe([]);

        $state->apply('client-workspaces', ['sequence' => 4, 'part' => 1, 'parts' => 2, 'workspaces' => [$workspace(1, 'a')]], 100.0);
        $state->apply('client-workspaces', ['sequence' => 5, 'part' => 2, 'parts' => 2, 'workspaces' => [$workspace(2, 'b')]], 100.0);
        expect(array_keys($state->workspaces))->toBe([1, 2])
            ->and($state->takeChangedWorkspaces())->toBe([1, 2])
            ->and($state->takeChangedWorkspaces())->toBe([]);

        $state->apply('client-workspaces', ['sequence' => 6, 'part' => 1, 'parts' => 1, 'workspaces' => [$workspace(2, 'b')]], 101.0);
        expect(array_keys($state->workspaces))->toBe([2])
            ->and($state->takeChangedWorkspaces())->toBe([]);

        $state->apply('client-workspace', ['sequence' => 7, 'workspace' => $workspace(2, 'c')], 102.0);
        expect($state->takeChangedWorkspaces())->toBe([2]);

        $state->apply('client-heartbeat', ['sequence' => 1], 110.0);
        expect($state->workspaces)->toBe([]);
    });
});
