<?php

declare(strict_types=1);

use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use Illuminate\Support\Facades\Event;

describe('AppInstance record events', function (): void {
    it('broadcasts instance.updated when the deployment branch changes', function (): void {
        [$caller, $owner, $orbitApp, $instance] = deployment_api_fixture();

        Event::fake([RecordBroadcast::class]);

        $this
            ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
            ->patchJson("/api/v1/instances/{$instance->id}", [
                'branch' => 'release-2026-09',
            ])
            ->assertOk();

        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::InstanceUpdated
                && $event->id === $instance->id,
        );
    });
});
