<?php

declare(strict_types=1);

use App\Domain\AppInstances\Queue\AppInstanceQueueReader;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\Process;
use App\Models\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\Orb220DeploymentApiFixture;

final class RecordingAppInstanceQueueReader implements AppInstanceQueueReader
{
    /** @var list<array{int, string, int}> */
    public array $reads = [];

    /** @param array<array-key, mixed> $report */
    public function __construct(public array $report = [], public bool $fails = false) {}

    public function read(Process $horizon, string $state, int $limit): array
    {
        $this->reads[] = [$horizon->id, $state, $limit];

        if ($this->fails) {
            throw new ResourceOperationException('instance.queue_failed', 'The queue could not be read.', 502);
        }

        return $this->report;
    }
}

function queue_api_process(AppInstance $instance, string $name, string $command): Process
{
    return Process::query()->create([
        'owner_type' => AppInstance::class,
        'owner_id' => $instance->id,
        'name' => $name,
        'runtime' => 'systemd',
        'working_directory' => $instance->checkout_path,
        'runtime_config' => ['command' => ['/usr/bin/php', 'artisan', $command]],
        'restart_policy' => 'always',
        'desired_state' => 'running',
        'status' => LifecycleStatus::Active,
    ]);
}

beforeEach(function (): void {
    $this->fixture = Orb220DeploymentApiFixture::create();
    $route = Route::query()->create([
        'app_id' => $this->fixture->instance->app_id,
        'node_id' => $this->fixture->instance->node_id,
        'generation_basis_node_id' => $this->fixture->instance->node_id,
        'domain' => 'shop.test',
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
    ]);
    $route->targets()->create(['app_instance_id' => $this->fixture->instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $this->reader = new RecordingAppInstanceQueueReader([
        'installed' => true,
        'path' => 'horizon',
        'status' => 'running',
        'jobs_per_minute' => 69,
        'recent_jobs' => 104,
        'recently_failed_jobs' => 1,
        'processes' => 3,
        'totals' => ['pending' => 0, 'completed' => 104, 'failed' => 1],
        'queues' => [['name' => 'default', 'length' => 2, 'wait_seconds' => 4, 'processes' => 1]],
        'jobs' => [[
            'id' => '497ef085-65c7-4707-bbc6-bc0ca2f8a35d',
            'name' => 'App\\Jobs\\SendInvoice',
            'queue' => 'default',
            'status' => 'failed',
            'pushed_at' => '2026-09-19T22:31:00Z',
            'completed_at' => null,
            'failed_at' => '2026-09-19T22:31:02Z',
            'exception' => 'RuntimeException: The mail server refused the message.',
            'payload' => '{"secret":"must never leave the Gateway"}',
        ]],
    ]);
    $this->app->instance(AppInstanceQueueReader::class, $this->reader);
    $this->read = fn (string $query = ''): TestResponse => $this
        ->withServerVariables(['REMOTE_ADDR' => $this->fixture->caller->wireguard_ip])
        ->getJson("/api/v1/instances/{$this->fixture->instance->id}/queue{$query}");
});

describe('instance:queue', function (): void {
    it('reports an instance without a horizon Process as unavailable and reads nothing', function (): void {
        queue_api_process($this->fixture->instance, 'queue', 'queue:work');

        ($this->read)()->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonMissingPath('data.jobs');

        expect($this->reader->reads)->toBe([]);
    });

    it('reports an application without Horizon installed as unavailable', function (): void {
        queue_api_process($this->fixture->instance, 'horizon', 'horizon');
        $this->reader->report = ['installed' => false];

        ($this->read)()->assertOk()->assertJsonPath('data.available', false);
    });

    it('returns the summary, the queues, and the jobs of the asked state through the horizon Process', function (): void {
        $horizon = queue_api_process($this->fixture->instance, 'horizon', 'horizon');

        $response = ($this->read)('?state=failed&limit=10')->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.process_id', $horizon->id)
            ->assertJsonPath('data.status', 'running')
            ->assertJsonPath('data.jobs_per_minute', 69)
            ->assertJsonPath('data.totals', ['pending' => 0, 'completed' => 104, 'failed' => 1])
            ->assertJsonPath('data.queues.0', ['name' => 'default', 'length' => 2, 'wait_seconds' => 4, 'processes' => 1])
            ->assertJsonPath('data.state', 'failed')
            ->assertJsonPath('data.jobs.0.name', 'App\\Jobs\\SendInvoice')
            ->assertJsonPath('data.jobs.0.failed_at', '2026-09-19T22:31:02Z')
            ->assertJsonPath('data.jobs.0.exception', 'RuntimeException: The mail server refused the message.');

        expect($this->reader->reads)->toBe([[$horizon->id, 'failed', 10]])
            ->and($response->json('data.dashboard_url'))->toBe('https://shop.test/horizon')
            ->and($response->json('data.jobs.0.url'))
            ->toBe($response->json('data.dashboard_url').'/failed/497ef085-65c7-4707-bbc6-bc0ca2f8a35d');
    });

    it('never passes a job payload or an unknown field on', function (): void {
        queue_api_process($this->fixture->instance, 'horizon', 'horizon');

        $response = ($this->read)('?state=failed')->assertOk();

        expect($response->getContent())->not->toContain('must never leave the Gateway')
            ->and(array_keys($response->json('data.jobs.0')))
            ->toBe(['id', 'name', 'queue', 'status', 'pushed_at', 'completed_at', 'failed_at', 'exception', 'url']);
    });

    it('bounds what the application printed', function (): void {
        queue_api_process($this->fixture->instance, 'horizon', 'horizon');
        $this->reader->report['jobs'][0]['name'] = str_repeat('n', 500);
        $this->reader->report['jobs'][0]['id'] = '../../admin';
        $this->reader->report['jobs'][0]['pushed_at'] = '<script>';
        $this->reader->report['jobs_per_minute'] = 'many';

        ($this->read)()->assertOk()
            ->assertJsonPath('data.jobs.0.name', str_repeat('n', 200))
            ->assertJsonPath('data.jobs.0.url', null)
            ->assertJsonPath('data.jobs.0.pushed_at', null)
            ->assertJsonPath('data.jobs_per_minute', 0);
    });

    it('links pending and completed jobs under the jobs path, and reports no exception as null', function (): void {
        queue_api_process($this->fixture->instance, 'horizon', 'horizon');
        $this->reader->report['jobs'][0]['exception'] = '';

        ($this->read)('?state=completed')->assertJsonPath('data.jobs.0.exception', null);

        $url = ($this->read)('?state=completed')->json('data.jobs.0.url');

        expect($url)->toEndWith('/horizon/jobs/completed/497ef085-65c7-4707-bbc6-bc0ca2f8a35d');
    });

    it('rejects an unknown state and a limit outside one to fifty', function (string $query): void {
        queue_api_process($this->fixture->instance, 'horizon', 'horizon');

        ($this->read)($query)->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');

        expect($this->reader->reads)->toBe([]);
    })->with(['?state=reserved', '?limit=0', '?limit=51']);

    it('reports a queue the node did not return', function (): void {
        queue_api_process($this->fixture->instance, 'horizon', 'horizon');
        $this->reader->fails = true;

        ($this->read)()->assertStatus(502)->assertJsonPath('error.code', 'instance.queue_failed');
    });
});
