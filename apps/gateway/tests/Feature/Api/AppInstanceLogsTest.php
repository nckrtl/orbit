<?php

declare(strict_types=1);

use App\Domain\AppInstances\Logs\AppInstanceLogReader;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\AppInstanceEnvironmentValue;
use Illuminate\Testing\TestResponse;
use Tests\Support\Orb220DeploymentApiFixture;

final class RecordingAppInstanceLogReader implements AppInstanceLogReader
{
    /** @var list<array{int, int}> */
    public array $reads = [];

    public function __construct(public string $log = '', public bool $fails = false) {}

    public function tail(AppInstance $instance, int $lines): string
    {
        $this->reads[] = [$instance->id, $lines];

        if ($this->fails) {
            throw new ResourceOperationException('instance.logs_failed', 'The log could not be read.', 502);
        }

        return $this->log;
    }
}

beforeEach(function (): void {
    $this->fixture = Orb220DeploymentApiFixture::create();
    $this->reader = new RecordingAppInstanceLogReader;
    $this->app->instance(AppInstanceLogReader::class, $this->reader);
    $this->url = "/api/v1/instances/{$this->fixture->instance->id}/logs";
    $this->read = fn (string $query = ''): TestResponse => $this
        ->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip])
        ->getJson($this->url.$query);
});

describe('instance:logs', function (): void {
    it('returns the last hundred lines of the application log by default', function (): void {
        $this->reader->log = "[2026-09-19 10:00:00] local.ERROR: Something broke\n";

        ($this->read)()
            ->assertOk()
            ->assertJsonPath('data.id', $this->fixture->instance->id)
            ->assertJsonPath('data.name', $this->fixture->instance->name)
            ->assertJsonPath('data.lines', 100)
            ->assertJsonPath('data.logs', "[2026-09-19 10:00:00] local.ERROR: Something broke\n");

        expect($this->reader->reads)->toBe([[$this->fixture->instance->id, 100]]);
    });

    it('reads the number of lines the caller asks for', function (): void {
        ($this->read)('?lines=25')->assertOk()->assertJsonPath('data.lines', 25);

        expect($this->reader->reads)->toBe([[$this->fixture->instance->id, 25]]);
    });

    it('returns an empty log for an instance that has no application log', function (): void {
        ($this->read)()->assertOk()->assertJsonPath('data.logs', '');
    });

    it('redacts instance environment values and secret patterns', function (): void {
        AppInstanceEnvironmentValue::query()->create([
            'app_instance_id' => $this->fixture->instance->id,
            'env_key' => 'PAYMENT_SECRET',
            'env_value' => 'orbit-test-secret-value-4821',
        ]);
        AppInstanceEnvironmentValue::query()->create([
            'app_instance_id' => $this->fixture->instance->id,
            'env_key' => 'APP_ENV',
            'env_value' => 'local',
        ]);
        $this->reader->log = 'local.ERROR: charge failed with key orbit-test-secret-value-4821; Authorization: Bearer disposable-log-token-4821';

        $logs = ($this->read)()->assertOk()->json('data.logs');

        expect($logs)->not->toContain('orbit-test-secret-value-4821')
            ->and($logs)->toContain('[REDACTED]')
            ->and($logs)->toContain('local.ERROR');
        expect($logs)->not->toContain('disposable-log-token-4821');
    });

    it('rejects a line count outside one to a thousand, and following', function (string $query): void {
        ($this->read)($query)->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');

        expect($this->reader->reads)->toBe([]);
    })->with([
        'zero lines' => ['?lines=0'],
        'too many lines' => ['?lines=1001'],
        'follow' => ['?follow=1'],
    ]);

    it('reports a log the node did not return', function (): void {
        $this->reader->fails = true;

        ($this->read)()->assertStatus(502)->assertJsonPath('error.code', 'instance.logs_failed');
    });
});
