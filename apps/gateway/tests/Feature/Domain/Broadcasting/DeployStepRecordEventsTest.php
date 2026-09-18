<?php

declare(strict_types=1);

use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    [$this->caller, $this->owner, $this->orbitApp, $this->instance] = deployment_api_fixture();
    $this->url = "/api/v1/instances/{$this->instance->id}/deploy-steps";
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
});

describe('Deploy step record events', function (): void {
    it('broadcasts deploy_step.created when a step is added', function (): void {
        Event::fake([RecordBroadcast::class]);

        $this->postJson($this->url, [
            'name' => 'migrate',
            'command' => 'php artisan migrate --force',
        ])->assertCreated();

        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::DeployStepCreated
                && $event->id === 'migrate'
                && $event->data['name'] === 'migrate',
        );
    });

    it('broadcasts deploy_step.updated when a step changes', function (): void {
        $this->postJson($this->url, [
            'name' => 'migrate',
            'command' => 'php artisan migrate --force',
        ])->assertCreated();

        Event::fake([RecordBroadcast::class]);

        $this->patchJson("{$this->url}/migrate", [
            'timeout_seconds' => 120,
        ])->assertOk();

        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::DeployStepUpdated
                && $event->id === 'migrate'
                && $event->data['timeout_seconds'] === 120,
        );
    });

    it('broadcasts deploy_step.deleted when a step is removed', function (): void {
        $this->postJson($this->url, [
            'name' => 'migrate',
            'command' => 'php artisan migrate --force',
        ])->assertCreated();

        Event::fake([RecordBroadcast::class]);

        $this->deleteJson("{$this->url}/migrate")->assertOk();

        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::DeployStepDeleted
                && $event->id === 'migrate',
        );
    });
});
