<?php

declare(strict_types=1);

use App\Models\Activity;
use Illuminate\Support\Str;
use Tests\Support\Orb220DeploymentApiFixture;

beforeEach(function (): void {
    $this->fixture = Orb220DeploymentApiFixture::create();
    $this->url = "/api/v1/instances/{$this->fixture->instance->id}/deploy";
});

it('streams the closed phase output and terminal event schema with bounded lines', function (): void {
    $requestId = (string) Str::uuid();
    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip])
        ->withHeader('X-Orbit-Request-Id', $requestId)
        ->call('POST', $this->url, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/x-ndjson',
            'HTTP_X_ORBIT_REQUEST_ID' => $requestId,
        ], content: '{}');

    $response
        ->assertOk()
        ->assertStreamed()
        ->assertHeader('Content-Type', 'application/x-ndjson')
        ->assertHeader('X-Accel-Buffering', 'no');

    expect(Activity::query()->sole()->status)->toBe('running');

    $content = $response->streamedContent();
    $rawLines = array_values(array_filter(explode("\n", $content)));
    $events = array_map(
        static fn (string $line): array => json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR),
        $rawLines,
    );

    expect(array_column($events, 'sequence'))
        ->toBe(range(1, count($events)))
        ->and(array_values(array_unique(array_column($events, 'request_id'))))
        ->toBe([$requestId])
        ->and(array_column($events, 'type'))
        ->toBe(['phase', 'phase', 'phase', 'output', 'phase', 'phase', 'phase', 'output', 'result'])
        ->and(array_column(array_filter($events, static fn (array $event): bool => $event['type'] === 'phase'), 'phase'))
        ->toBe([
            'source_preparation',
            'environment_sync',
            'before_activation',
            'activation',
            'php_refresh',
            'after_activation',
        ]);

    foreach ($events as $event) {
        $expectedKeys = match ($event['type']) {
            'phase' => isset($event['step_name'])
                ? ['type', 'sequence', 'request_id', 'phase', 'step_name']
                : ['type', 'sequence', 'request_id', 'phase'],
            'output' => ['type', 'sequence', 'request_id', 'stream', 'data_base64'],
            'result' => [
                'type',
                'sequence',
                'request_id',
                'status',
                'failed_step',
                'error_code',
                'selected_release',
            ],
        };

        expect(array_keys($event))->toBe($expectedKeys);
    }

    $phases = array_values(array_filter($events, static fn (array $event): bool => $event['type'] === 'phase'));
    expect($phases[2]['step_name'])
        ->toBe('prepare')
        ->and($phases[5]['step_name'])
        ->toBe('finish');

    foreach ($phases as $index => $phase) {
        if (in_array($index, [2, 5], strict: true)) {
            continue;
        }

        expect($phase)->not->toHaveKey('step_name');
    }

    $outputs = array_values(array_filter($events, static fn (array $event): bool => $event['type'] === 'output'));
    expect(array_column($outputs, 'stream'))
        ->toBe(['stdout', 'stderr'])
        ->and(base64_decode($outputs[0]['data_base64'], strict: true))
        ->toBe(str_repeat("\xff", 16 * 1024))
        ->and(base64_decode($outputs[1]['data_base64'], strict: true))
        ->toBe("output-secret\0bytes")
        ->and(max(array_map('strlen', $rawLines)))
        ->toBeLessThanOrEqual(32 * 1024)
        ->and($events[array_key_last($events)])
        ->toMatchArray([
            'type' => 'result',
            'status' => 'succeeded',
            'failed_step' => null,
            'error_code' => null,
            'selected_release' => 'fresh',
        ]);

    $activity = Activity::query()->sole()->refresh();
    expect($activity->status)
        ->toBe('succeeded')
        ->and($activity->properties?->get('input'))
        ->toBe([])
        ->and($activity->properties?->get('deployment'))
        ->toBe([
            'status' => 'succeeded',
            'selected_release' => 'fresh',
            'failed_step' => null,
            'error_code' => null,
        ])
        ->and(json_encode($activity->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain('output-secret', 'data_base64', 'prepare-command', 'finish-command');
});

it('ends a post-admission execution failure with one failed result', function (): void {
    $this->fixture->deployment->failPreparation = true;
    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip])
        ->call('POST', $this->url, server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
    $events = array_map(
        static fn (string $line): array => json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR),
        array_values(array_filter(explode("\n", $response->streamedContent()))),
    );
    $results = array_values(array_filter($events, static fn (array $event): bool => $event['type'] === 'result'));

    expect($results)
        ->toHaveCount(1)
        ->and($events[array_key_last($events)])
        ->toBe($results[0])
        ->and($results[0])
        ->toMatchArray([
            'status' => 'failed',
            'failed_step' => 'preparation',
            'error_code' => 'deployment.prepare_failed',
            'selected_release' => 'initial',
        ])
        ->and(Activity::query()->sole()->refresh()->status)
        ->toBe('failed')
        ->and(Activity::query()->sole()->error_code)
        ->toBe('deployment.prepare_failed');
});

it('signals cancellation and suppresses a terminal result after disconnect without replay', function (): void {
    $this->fixture->connection->disconnectOnOutput = true;
    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip])
        ->call('POST', $this->url, server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
    $events = array_map(
        static fn (string $line): array => json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR),
        array_values(array_filter(explode("\n", $response->streamedContent()))),
    );

    expect(array_column($events, 'type'))
        ->not->toContain('result')
        ->and($this->fixture->deployment->invocations)
        ->toBe(1)
        ->and(Activity::query()->sole()->refresh()->status)
        ->toBe('failed')
        ->and(Activity::query()->sole()->error_code)
        ->toBe('deployment.cancelled');
});

it('stops before activation when the next phase write detects a disconnect after a silent step', function (): void {
    $this->fixture->deployment->disconnectAfterSilentStep = true;
    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip])
        ->call('POST', $this->url, server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
    $completeLines = explode("\n", $response->streamedContent());
    array_pop($completeLines);
    $events = array_map(
        static fn (string $line): array => json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR),
        array_values(array_filter($completeLines)),
    );
    $activity = Activity::query()->sole()->refresh();

    expect(array_column($events, 'type'))
        ->not->toContain('result')
        ->and(array_column($events, 'phase'))
        ->not->toContain('activation')
        ->and($this->fixture->connection->phaseProbeWrites)
        ->toBe(5)
        ->and($this->fixture->deployment->activations)
        ->toBe(0)
        ->and($this->fixture->deployment->invocations)
        ->toBe(1)
        ->and($activity->status)
        ->toBe('failed')
        ->and($activity->error_code)
        ->toBe('deployment.cancelled')
        ->and($activity->properties?->get('deployment')['failed_step'])
        ->toBe('activation')
        ->and($activity->properties?->get('deployment')['selected_release'])
        ->toBe('initial');
});

it('streams rollback with its release input and rollback phase', function (): void {
    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip])
        ->call(
            'POST',
            "/api/v1/instances/{$this->fixture->instance->id}/rollback",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"release":"initial"}',
        );
    $events = array_map(
        static fn (string $line): array => json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR),
        array_values(array_filter(explode("\n", $response->streamedContent()))),
    );

    expect($events[0])
        ->toMatchArray(['type' => 'phase', 'phase' => 'rollback'])
        ->and($events[array_key_last($events)])
        ->toMatchArray(['type' => 'result', 'status' => 'succeeded', 'selected_release' => 'initial'])
        ->and($this->fixture->deployment->requestedRelease)
        ->toBe('initial')
        ->and(Activity::query()->sole()->refresh()->properties?->get('input'))
        ->toBe(['release' => 'initial']);
});
