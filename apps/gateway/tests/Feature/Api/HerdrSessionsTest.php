<?php

declare(strict_types=1);

use App\Domain\Herdr\HerdrLivePane;
use App\Domain\Herdr\HerdrObserverPublisher;
use App\Domain\Herdr\HerdrSessionInspector;
use App\Domain\Herdr\ObservationGrantSigner;
use App\Domain\Herdr\ObservationGrantValidator;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolStatus;
use App\Models\HerdrObservationNonce;
use App\Models\HerdrSession;
use App\Models\Node;
use App\Models\Process;
use App\Models\Tool;
use Tests\Support\FakeHerdrObserverPublisher;
use Tests\Support\FakeHerdrSessionInspector;
use Tests\Support\ProcessesApiFakeRuntimeManager;

beforeEach(function (): void {
    $this->runtime = new ProcessesApiFakeRuntimeManager;
    app()->instance(ProcessRuntimeManager::class, $this->runtime);
    $this->observers = new FakeHerdrObserverPublisher;
    app()->instance(HerdrObserverPublisher::class, $this->observers);
    $this->inspector = new FakeHerdrSessionInspector;
    app()->instance(HerdrSessionInspector::class, $this->inspector);

    $node = Node::query()->create([
        'name' => 'beast',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'public_ssh_port' => 22,
        'user' => 'nckrtl',
        'tld' => 'orbit',
        'wireguard_ip' => '10.44.0.8',
    ]);
    $this->node = $this->markAsGateway($node);
    $this->herdrTool = herdr_sessions_install_tool($this->node);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
});

it('creates a named Herdr session on a managed Node with a private observer', function (): void {
    $response = $this->postJson('/api/v1/herdr/sessions', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.node', 'beast')
        ->assertJsonPath('data.session', 'commander-tasks')
        ->assertJsonPath('data.user', 'nckrtl')
        ->assertJsonPath('data.management', 'managed')
        ->assertJsonPath('data.observer_url', 'wss://commander-tasks.herdr.beast.orbit')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.herdr_version', '0.9.0')
        ->assertJsonPath('data.protocol', 22)
        ->assertJsonPath('data.health.process', 'healthy')
        ->assertJsonPath('data.health.listener', 'healthy')
        ->assertJsonPath('data.health.session', 'healthy');

    $session = HerdrSession::query()->sole();
    $process = Process::query()->sole();

    expect($session->process_id)
        ->toBe($process->id)
        ->and($process->name)
        ->toBe('herdr-commander-tasks')
        ->and($process->owner_type)
        ->toBe(Node::class)
        ->and($process->runtime_config['command'])
        ->toBe([
            '/home/linuxbrew/.linuxbrew/bin/herdr',
            '--session',
            'commander-tasks',
            'server',
        ])
        ->and($this->runtime->convergedProcessIds)
        ->toBe([$process->id])
        ->and($this->observers->published)
        ->toBe(['commander-tasks']);

    $this->assertDatabaseHas('activity_log', [
        'command' => 'herdr:session:create',
        'status' => 'succeeded',
    ]);
});

it('adopts an existing session for observation without creating or controlling a Process', function (): void {
    $response = $this->postJson('/api/v1/herdr/sessions/adopt', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.management', 'external')
        ->assertJsonPath('data.process_id', null)
        ->assertJsonPath('data.observer_url', 'wss://commander-tasks.herdr.beast.orbit')
        ->assertJsonPath('data.health.process', 'external')
        ->assertJsonPath('data.health.listener', 'healthy')
        ->assertJsonPath('data.health.session', 'healthy');

    expect(Process::query()->count())
        ->toBe(0)
        ->and($this->runtime->convergedProcessIds)
        ->toBeEmpty()
        ->and($this->runtime->started)
        ->toBeEmpty()
        ->and($this->runtime->restarted)
        ->toBeEmpty()
        ->and($this->runtime->stopped)
        ->toBeEmpty()
        ->and($this->observers->published)
        ->toBe(['commander-tasks']);

    $this->assertDatabaseHas('activity_log', [
        'command' => 'herdr:session:adopt',
        'status' => 'succeeded',
    ]);
});

it('forbids adoption by a peer without directed access to the target Node', function (): void {
    $consumer = Node::query()->create([
        'name' => 'unauthorized-consumer',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.21',
        'public_ssh_port' => 22,
        'user' => 'nckrtl',
        'wireguard_ip' => '10.44.0.9',
    ]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $consumer->wireguard_ip])
        ->postJson('/api/v1/herdr/sessions/adopt', [
            'node_id' => $this->node->id,
            'session' => 'commander-tasks',
            'user' => 'nckrtl',
            'publish_observer' => true,
        ])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'node_access.required');

    expect(HerdrSession::query()->count())
        ->toBe(0)
        ->and(Process::query()->count())
        ->toBe(0)
        ->and($this->observers->published)
        ->toBeEmpty();
});

it('refuses adoption without Herdr Tool intent on the target Node', function (): void {
    $this->herdrTool->delete();

    $this->postJson('/api/v1/herdr/sessions/adopt', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'herdr.tool_not_installed');

    expect($this->inspector->inspections)
        ->toBe(0)
        ->and(HerdrSession::query()->count())
        ->toBe(0)
        ->and(Process::query()->count())
        ->toBe(0)
        ->and($this->runtime->convergedProcessIds)
        ->toBeEmpty()
        ->and($this->observers->published)
        ->toBeEmpty();
});

it('refuses adoption when Herdr Tool intent on the target Node has failed', function (): void {
    $this->herdrTool->update([
        'status' => ToolStatus::Failed,
        'error_code' => 'tool.install_failed',
    ]);

    $this->postJson('/api/v1/herdr/sessions/adopt', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'herdr.tool_not_installed');

    expect($this->inspector->inspections)
        ->toBe(0)
        ->and(HerdrSession::query()->count())
        ->toBe(0)
        ->and(Process::query()->count())
        ->toBe(0)
        ->and($this->runtime->convergedProcessIds)
        ->toBeEmpty()
        ->and($this->observers->published)
        ->toBeEmpty();
});

it('does not accept Herdr Tool intent from a different Node for adoption', function (): void {
    $this->herdrTool->delete();
    $other = Node::query()->create([
        'name' => 'workhorse',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.22',
        'public_ssh_port' => 22,
        'user' => 'nckrtl',
        'wireguard_ip' => '10.44.0.10',
    ]);
    herdr_sessions_install_tool($other);

    $this->postJson('/api/v1/herdr/sessions/adopt', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'herdr.tool_not_installed');

    expect($this->inspector->inspections)
        ->toBe(0)
        ->and(HerdrSession::query()->count())
        ->toBe(0)
        ->and(Process::query()->count())
        ->toBe(0)
        ->and($this->observers->published)
        ->toBeEmpty();
});

it('refuses adoption for a Unix user that does not own the managed Node', function (): void {
    $this->postJson('/api/v1/herdr/sessions/adopt', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'other-user',
        'publish_observer' => true,
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'herdr.user_mismatch');

    expect($this->inspector->inspections)
        ->toBe(0)
        ->and(HerdrSession::query()->count())
        ->toBe(0)
        ->and(Process::query()->count())
        ->toBe(0)
        ->and($this->observers->published)
        ->toBeEmpty();
});

it('rejects an invalid external session name before adoption', function (): void {
    $this->postJson('/api/v1/herdr/sessions/adopt', [
        'node_id' => $this->node->id,
        'session' => 'Invalid Session',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.details.session.0', 'The session field format is invalid.');

    expect($this->inspector->inspections)
        ->toBe(0)
        ->and(HerdrSession::query()->count())
        ->toBe(0)
        ->and(Process::query()->count())
        ->toBe(0)
        ->and($this->observers->published)
        ->toBeEmpty();
});

it('refuses adoption when an Orbit Process already reserves the session name', function (): void {
    Process::query()->create([
        'owner_type' => Node::class,
        'owner_id' => $this->node->id,
        'name' => 'herdr-commander-tasks',
        'runtime' => 'systemd',
        'working_directory' => '.',
        'restart_policy' => 'always',
        'desired_state' => 'running',
        'status' => LifecycleStatus::Active,
        'runtime_config' => [],
    ]);

    $this->postJson('/api/v1/herdr/sessions/adopt', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'herdr.session_conflict');

    expect($this->inspector->inspections)
        ->toBe(0)
        ->and(HerdrSession::query()->count())
        ->toBe(0)
        ->and(Process::query()->count())
        ->toBe(1)
        ->and($this->runtime->convergedProcessIds)
        ->toBeEmpty()
        ->and($this->observers->published)
        ->toBeEmpty();
});

it('repeats identical external adoption without republishing or creating a Process', function (): void {
    $payload = [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ];

    $this->postJson('/api/v1/herdr/sessions/adopt', $payload)->assertCreated();
    $this->postJson('/api/v1/herdr/sessions/adopt', $payload)
        ->assertOk()
        ->assertJsonPath('data.management', 'external');

    expect(HerdrSession::query()->count())
        ->toBe(1)
        ->and(Process::query()->count())
        ->toBe(0)
        ->and($this->observers->published)
        ->toBe(['commander-tasks']);
});

it('retains an external protocol 20 observation without publishing it', function (): void {
    $this->inspector->protocol = 20;

    $this->postJson('/api/v1/herdr/sessions/adopt', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'herdr.observer_unsupported');

    $session = HerdrSession::query()->sole();
    expect($session->management->value)
        ->toBe('external')
        ->and($session->protocol)
        ->toBe(20)
        ->and($session->process_id)
        ->toBeNull()
        ->and(Process::query()->count())
        ->toBe(0)
        ->and($this->observers->published)
        ->toBeEmpty();
});

it('publishes a retained external observation after the session protocol is upgraded', function (): void {
    $this->inspector->protocol = 20;
    $payload = [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ];

    $this->postJson('/api/v1/herdr/sessions/adopt', $payload)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'herdr.observer_unsupported');

    $this->inspector->protocol = 22;

    $this->postJson('/api/v1/herdr/sessions/adopt', $payload)
        ->assertOk()
        ->assertJsonPath('data.protocol', 22)
        ->assertJsonPath('data.observer_url', 'wss://commander-tasks.herdr.beast.orbit')
        ->assertJsonPath('data.status', 'active');

    expect(HerdrSession::query()->count())
        ->toBe(1)
        ->and(Process::query()->count())
        ->toBe(0)
        ->and($this->observers->published)
        ->toBe(['commander-tasks']);
});

it('does not retain an external session when live inspection fails', function (): void {
    $this->inspector->failure = new RuntimeException('untrusted transport detail');

    $this->postJson('/api/v1/herdr/sessions/adopt', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'herdr.inspection_failed')
        ->assertJsonMissing(['message' => 'untrusted transport detail']);

    expect(HerdrSession::query()->count())->toBe(0)->and(Process::query()->count())->toBe(0);
});

it('refuses to adopt a session already managed by Orbit', function (): void {
    $this->postJson('/api/v1/herdr/sessions', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
    ])->assertCreated();

    $this->postJson('/api/v1/herdr/sessions/adopt', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
    ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'herdr.session_conflict');

    expect(HerdrSession::query()->sole()->management->value)->toBe('managed');
});

it('refuses to create over a session adopted for observation', function (): void {
    $this->postJson('/api/v1/herdr/sessions/adopt', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
    ])->assertCreated();

    $this->postJson('/api/v1/herdr/sessions', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
    ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'herdr.session_conflict');

    expect(Process::query()->count())->toBe(0);
});

it('never restarts or terminates the service behind an externally managed session', function (): void {
    $this->postJson('/api/v1/herdr/sessions/adopt', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ])->assertCreated();
    $session = HerdrSession::query()->sole();

    $process = Process::query()->create([
        'owner_type' => Node::class,
        'owner_id' => $this->node->id,
        'name' => 'unrelated-process',
        'runtime' => 'systemd',
        'working_directory' => '.',
        'restart_policy' => 'always',
        'desired_state' => 'running',
        'status' => LifecycleStatus::Active,
        'runtime_config' => [],
    ]);
    $session->update(['process_id' => $process->id]);

    $this->herdrTool->delete();

    $this->postJson('/api/v1/herdr/sessions/'.$session->id.'/restart')
        ->assertConflict()
        ->assertJsonPath('error.code', 'herdr.session_external');

    $this->inspector->panes = [new HerdrLivePane('w1:p1', 'term-abc', true)];
    $this->deleteJson('/api/v1/herdr/sessions/'.$session->id)->assertOk();

    expect(HerdrSession::query()->count())
        ->toBe(0)
        ->and(Process::query()->count())
        ->toBe(1)
        ->and($this->runtime->stopped)
        ->toBeEmpty()
        ->and($this->runtime->restarted)
        ->toBeEmpty()
        ->and($this->runtime->removed)
        ->toBeEmpty()
        ->and($this->observers->retracted)
        ->toBe(['commander-tasks']);
});

it('returns 409 without runtime changes when Herdr Tool intent is missing', function (): void {
    $this->herdrTool->delete();

    $this->postJson('/api/v1/herdr/sessions', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'herdr.tool_not_installed');

    expect(HerdrSession::query()->count())
        ->toBe(0)
        ->and(Process::query()->count())
        ->toBe(0)
        ->and($this->runtime->convergedProcessIds)
        ->toBeEmpty()
        ->and($this->observers->published)
        ->toBeEmpty();
});

it('returns 409 without runtime changes when Herdr Tool intent has failed', function (): void {
    $this->herdrTool->update([
        'status' => ToolStatus::Failed,
        'error_code' => 'tool.install_failed',
    ]);

    $this->postJson('/api/v1/herdr/sessions', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'herdr.tool_not_installed');

    expect(HerdrSession::query()->count())
        ->toBe(0)
        ->and(Process::query()->count())
        ->toBe(0)
        ->and($this->runtime->convergedProcessIds)
        ->toBeEmpty();
});

it('returns 409 without runtime changes when the managed brew prerequisite is inactive', function (): void {
    $this->herdrTool->manager()->update(['status' => LifecycleStatus::Failed]);

    $this->postJson('/api/v1/herdr/sessions', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'herdr.tool_not_installed');

    expect(HerdrSession::query()->count())
        ->toBe(0)
        ->and(Process::query()->count())
        ->toBe(0)
        ->and($this->runtime->convergedProcessIds)
        ->toBeEmpty();
});

it('returns 409 without runtime changes when Herdr is managed outside Homebrew', function (): void {
    $this->herdrTool->manager()->update(['name' => ToolManagerName::Apt]);

    $this->postJson('/api/v1/herdr/sessions', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'herdr.tool_not_installed');

    expect(HerdrSession::query()->count())
        ->toBe(0)
        ->and(Process::query()->count())
        ->toBe(0)
        ->and($this->runtime->convergedProcessIds)
        ->toBeEmpty();
});

it('returns 409 when Herdr Tool intent belongs to another Node', function (): void {
    $this->herdrTool->delete();
    $other = Node::query()->create([
        'name' => 'other',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.21',
        'public_ssh_port' => 22,
        'user' => 'nckrtl',
        'wireguard_ip' => '10.44.0.9',
    ]);
    herdr_sessions_install_tool($other);

    $this->postJson('/api/v1/herdr/sessions', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'herdr.tool_not_installed');

    expect(HerdrSession::query()->count())
        ->toBe(0)
        ->and(Process::query()->count())
        ->toBe(0)
        ->and($this->runtime->convergedProcessIds)
        ->toBeEmpty();
});

it('lists a managed session when Herdr Tool intent is no longer installed', function (): void {
    $this->postJson('/api/v1/herdr/sessions', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ])->assertCreated();
    $this->herdrTool->delete();

    $this->getJson('/api/v1/herdr/sessions?node_id='.$this->node->id)
        ->assertOk()
        ->assertJsonPath('data.0.session', 'commander-tasks');
});

it('shows a managed session when Herdr Tool intent is no longer installed', function (): void {
    $this->postJson('/api/v1/herdr/sessions', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ])->assertCreated();
    $session = HerdrSession::query()->sole();
    $this->herdrTool->delete();

    $this->getJson('/api/v1/herdr/sessions/'.$session->id)
        ->assertOk()
        ->assertJsonPath('data.session', 'commander-tasks');
});

it('destroys a managed session when Herdr Tool intent is no longer installed', function (): void {
    $this->postJson('/api/v1/herdr/sessions', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ])->assertCreated();
    $session = HerdrSession::query()->sole();
    $this->herdrTool->delete();

    $this->deleteJson('/api/v1/herdr/sessions/'.$session->id)->assertOk();

    expect(HerdrSession::query()->count())
        ->toBe(0)
        ->and(Process::query()->count())
        ->toBe(0);
});

it('ensures an identical session without restarting a compatible running server', function (): void {
    $this->postJson('/api/v1/herdr/sessions', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ])->assertCreated();

    $processId = Process::query()->sole()->id;
    $this->runtime->convergedProcessIds = [];
    $this->runtime->started = [];

    $this->postJson('/api/v1/herdr/sessions', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ])
        ->assertOk()
        ->assertJsonPath('data.process_id', $processId);

    expect($this->runtime->convergedProcessIds)
        ->toBeEmpty()
        ->and($this->runtime->started)
        ->toBeEmpty()
        ->and(HerdrSession::query()->count())
        ->toBe(1);
});

it('refuses a Unix user that does not match the Node managed user', function (): void {
    $this->postJson('/api/v1/herdr/sessions', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'other',
        'publish_observer' => true,
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'herdr.user_mismatch');

    expect(HerdrSession::query()->count())->toBe(0)->and(Process::query()->count())->toBe(0);
});

it('keeps the Herdr session when observer publication fails', function (): void {
    $this->observers->failPublish = true;

    $this->postJson('/api/v1/herdr/sessions', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.health.process', 'healthy')
        ->assertJsonPath('data.health.listener', 'unhealthy')
        ->assertJsonPath('data.error_code', 'herdr.observer_failed');

    expect(Process::query()->count())->toBe(1);
});

it('returns 422 without publishing when the Herdr observe protocol is unsupported', function (?int $protocol): void {
    $this->inspector->protocol = $protocol;

    $this->postJson('/api/v1/herdr/sessions', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'herdr.observer_unsupported');

    $session = HerdrSession::query()->sole();

    expect($session->status)
        ->toBe(LifecycleStatus::Failed)
        ->and($session->observer_status)
        ->toBe('failed')
        ->and($session->protocol)
        ->toBe($protocol)
        ->and($this->observers->published)
        ->toBeEmpty()
        ->and(Process::query()->count())
        ->toBe(1);
})->with([
    'older protocol' => 20,
    'unknown protocol' => null,
]);

it('returns 422 without publishing when observer capability inspection fails', function (): void {
    $this->inspector->failure = new RuntimeException('untrusted transport detail');

    $this->postJson('/api/v1/herdr/sessions', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'herdr.inspection_failed')
        ->assertJsonMissing(['message' => 'untrusted transport detail']);

    $session = HerdrSession::query()->sole();

    expect($session->status)
        ->toBe(LifecycleStatus::Failed)
        ->and($session->observer_status)
        ->toBe('failed')
        ->and($this->observers->published)
        ->toBeEmpty();
});

it('issues a scoped receive-only observation grant for one pane', function (): void {
    $this->postJson('/api/v1/herdr/sessions', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ])->assertCreated();

    $session = HerdrSession::query()->sole();
    $grant = $this->postJson('/api/v1/herdr/sessions/'.$session->id.'/observation-grants', [
        'pane' => 'w1:p1',
        'terminal' => 'term-abc',
        'cols' => 120,
        'rows' => 40,
        'origin' => 'https://tasks.commander.test',
    ]);

    $grant
        ->assertCreated()
        ->assertJsonPath('data.scope', 'terminal.observe')
        ->assertJsonPath('data.pane', 'w1:p1')
        ->assertJsonPath('data.terminal', 'term-abc')
        ->assertJsonPath('data.cols', 120)
        ->assertJsonPath('data.rows', 40)
        ->assertJsonMissingPath('data.token');

    $url = $grant->json('data.observer_url');
    expect($url)
        ->toStartWith('wss://commander-tasks.herdr.beast.orbit?access_token=')
        ->and($url)
        ->not->toContain('ssh')
        ->and($url)
        ->not->toContain('input');

    $token = parse_url((string) $url, PHP_URL_QUERY);
    parse_str((string) $token, $query);
    $claims = app(ObservationGrantValidator::class)->validate(
        (string) $query['access_token'],
        $session->load('node'),
        'w1:p1',
        'term-abc',
        consumeNonce: false,
    );

    expect($claims->node)->toBe('beast')->and($claims->session)->toBe('commander-tasks');
    expect($claims->origin)->toBe('https://tasks.commander.test');
});

it('issues a scoped observation grant for a protocol 22 adopted session', function (): void {
    $this->postJson('/api/v1/herdr/sessions/adopt', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ])->assertCreated();
    $session = HerdrSession::query()->sole();

    $grant = $this->postJson('/api/v1/herdr/sessions/'.$session->id.'/observation-grants', [
        'pane' => 'w1:p1',
        'terminal' => 'term-abc',
        'cols' => 120,
        'rows' => 40,
        'origin' => 'https://tasks.commander.test',
    ]);

    $grant
        ->assertCreated()
        ->assertJsonPath('data.scope', 'terminal.observe')
        ->assertJsonPath('data.pane', 'w1:p1')
        ->assertJsonPath('data.terminal', 'term-abc');

    expect($session->management->value)
        ->toBe('external')
        ->and($grant->json('data.observer_url'))
        ->toStartWith('wss://commander-tasks.herdr.beast.orbit?access_token=')
        ->and(HerdrObservationNonce::query()->count())
        ->toBe(1);
});

it('returns 422 without a nonce for a retained protocol 20 adopted session', function (): void {
    $this->inspector->protocol = 20;

    $this->postJson('/api/v1/herdr/sessions/adopt', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ])->assertUnprocessable()->assertJsonPath('error.code', 'herdr.observer_unsupported');
    $session = HerdrSession::query()->sole();

    $this->postJson('/api/v1/herdr/sessions/'.$session->id.'/observation-grants', [
        'pane' => 'w1:p1',
        'terminal' => 'term-abc',
        'cols' => 120,
        'rows' => 40,
        'origin' => 'https://tasks.commander.test',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'herdr.observer_failed');

    expect($session->refresh()->management->value)
        ->toBe('external')
        ->and($session->protocol)
        ->toBe(20)
        ->and(HerdrObservationNonce::query()->count())
        ->toBe(0);
});

it('returns 422 without a nonce when the Herdr protocol drifts before a grant', function (): void {
    $this->postJson('/api/v1/herdr/sessions', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ])->assertCreated();
    $session = HerdrSession::query()->sole();
    $this->inspector->protocol = 20;

    $this->postJson('/api/v1/herdr/sessions/'.$session->id.'/observation-grants', [
        'pane' => 'w1:p1',
        'terminal' => 'term-abc',
        'cols' => 120,
        'rows' => 40,
        'origin' => 'https://tasks.commander.test',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'herdr.observer_unsupported');

    expect(HerdrObservationNonce::query()->count())
        ->toBe(0)
        ->and($session->refresh()->status)
        ->toBe(LifecycleStatus::Failed)
        ->and($session->observer_status)
        ->toBe('failed');
});

it('returns 422 without a nonce when inspection fails before a grant', function (): void {
    $this->postJson('/api/v1/herdr/sessions', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ])->assertCreated();
    $session = HerdrSession::query()->sole();
    $this->inspector->failure = new RuntimeException('untrusted transport detail');

    $this->postJson('/api/v1/herdr/sessions/'.$session->id.'/observation-grants', [
        'pane' => 'w1:p1',
        'terminal' => 'term-abc',
        'cols' => 120,
        'rows' => 40,
        'origin' => 'https://tasks.commander.test',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'herdr.inspection_failed')
        ->assertJsonMissing(['message' => 'untrusted transport detail']);

    expect(HerdrObservationNonce::query()->count())
        ->toBe(0)
        ->and($session->refresh()->observer_status)
        ->toBe('published');
});

it('rejects expired, wrong-node, and pane-mismatch grants', function (): void {
    $this->postJson('/api/v1/herdr/sessions', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ])->assertCreated();

    $other = Node::query()->create([
        'name' => 'other',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.21',
        'public_ssh_port' => 22,
        'user' => 'nckrtl',
        'wireguard_ip' => '10.44.0.9',
    ]);
    herdr_sessions_install_tool($other);
    $this->postJson('/api/v1/herdr/sessions', [
        'node_id' => $other->id,
        'session' => 'reviewer-tasks',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ])->assertCreated();

    $first = HerdrSession::query()->where('session', 'commander-tasks')->sole();
    $second = HerdrSession::query()->where('session', 'reviewer-tasks')->sole();
    $url = $this->postJson('/api/v1/herdr/sessions/'.$first->id.'/observation-grants', [
        'pane' => 'w1:p1',
        'terminal' => 'term-abc',
        'cols' => 120,
        'rows' => 40,
        'origin' => 'https://tasks.commander.test',
    ])->json('data.observer_url');
    parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);
    $token = (string) $query['access_token'];
    $validator = app(ObservationGrantValidator::class);

    expect(fn () => $validator->validate($token, $second->load('node'), 'w1:p1', 'term-abc'))
        ->toThrow(ResourceOperationException::class, 'does not match this Node')
        ->and(fn () => $validator->validate($token, $first->load('node'), 'w2:p2', 'term-abc'))
        ->toThrow(ResourceOperationException::class, 'does not match the recorded pane')
        ->and(fn () => $validator->validate($token, $first->load('node'), 'w1:p1', 'term-xyz'))
        ->toThrow(ResourceOperationException::class, 'does not match the recorded pane');

    $this->travel(61)->seconds();
    expect(fn () => $validator->validate($token, $first->load('node'), 'w1:p1', 'term-abc'))
        ->toThrow(ResourceOperationException::class, 'has expired');

    $this->travelBack();
    $freshUrl = $this->postJson('/api/v1/herdr/sessions/'.$first->id.'/observation-grants', [
        'pane' => 'w1:p1',
        'terminal' => 'term-abc',
        'cols' => 120,
        'rows' => 40,
        'origin' => 'https://tasks.commander.test',
    ])->json('data.observer_url');
    parse_str((string) parse_url((string) $freshUrl, PHP_URL_QUERY), $freshQuery);
    $freshToken = (string) $freshQuery['access_token'];
    $validator->validate($freshToken, $first->load('node'), 'w1:p1', 'term-abc');
    expect(fn () => $validator->validate($freshToken, $first->load('node'), 'w1:p1', 'term-abc'))
        ->toThrow(ResourceOperationException::class, 'already been used');
});

it('publishes JWKS without granting input or pane discovery', function (): void {
    $jwks = $this->getJson('/.well-known/jwks.json')->assertOk()->json();

    expect($jwks['keys'][0]['kty'])
        ->toBe('RSA')
        ->and($jwks['keys'][0]['use'])
        ->toBe('sig')
        ->and($jwks)
        ->not->toHaveKey('private_pem');

    $signer = app(ObservationGrantSigner::class);
    expect($signer->jwks()['keys'][0]['kid'])->toBe($jwks['keys'][0]['kid']);
});

it('refuses removal while live panes exist unless termination is accepted', function (): void {
    $this->postJson('/api/v1/herdr/sessions', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ])->assertCreated();
    $session = HerdrSession::query()->sole();
    $this->inspector->panes = [new HerdrLivePane('w1:p1', 'term-abc', true)];

    $this->deleteJson('/api/v1/herdr/sessions/'.$session->id)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'herdr.session_in_use');

    expect(HerdrSession::query()->count())->toBe(1)->and(Process::query()->count())->toBe(1);

    $this->deleteJson('/api/v1/herdr/sessions/'.$session->id, ['accept_termination' => true])
        ->assertOk();

    expect(HerdrSession::query()->count())->toBe(0)->and(Process::query()->count())->toBe(0);
    $this->assertDatabaseHas('activity_log', [
        'command' => 'herdr:session:destroy',
        'status' => 'succeeded',
    ]);
});

it('restarts with Herdr handoff when requested and supported', function (): void {
    $this->postJson('/api/v1/herdr/sessions', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ])->assertCreated();
    $session = HerdrSession::query()->sole();

    $this->postJson('/api/v1/herdr/sessions/'.$session->id.'/restart', ['handoff' => true])
        ->assertOk();

    expect($this->inspector->handoffs)->toBe(1);
});

it('returns 409 without restarting when managed Herdr Tool intent is no longer installed', function (): void {
    $this->postJson('/api/v1/herdr/sessions', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ])->assertCreated();
    $session = HerdrSession::query()->sole();
    $this->herdrTool->update(['status' => ToolStatus::Failed]);
    $this->observers->published = [];
    $before = $session->getAttributes();

    $this->postJson('/api/v1/herdr/sessions/'.$session->id.'/restart')
        ->assertConflict()
        ->assertJsonPath('error.code', 'herdr.tool_not_installed');

    expect($this->runtime->restarted)
        ->toBeEmpty()
        ->and($this->observers->published)
        ->toBeEmpty()
        ->and($session->refresh()->getAttributes())
        ->toBe($before);
});

it('returns 409 without a grant when managed Herdr Tool intent is no longer installed', function (): void {
    $this->postJson('/api/v1/herdr/sessions', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ])->assertCreated();
    $session = HerdrSession::query()->sole();
    $this->herdrTool->update(['status' => ToolStatus::Failed]);
    $before = $session->getAttributes();

    $this->postJson('/api/v1/herdr/sessions/'.$session->id.'/observation-grants', [
        'pane' => 'w1:p1',
        'terminal' => 'term-abc',
        'cols' => 120,
        'rows' => 40,
        'origin' => 'https://tasks.commander.test',
    ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'herdr.tool_not_installed');

    expect(HerdrObservationNonce::query()->count())
        ->toBe(0)
        ->and($session->refresh()->getAttributes())
        ->toBe($before);
});

it('returns 422 without republishing when the Herdr protocol drifts during restart', function (): void {
    $this->postJson('/api/v1/herdr/sessions', [
        'node_id' => $this->node->id,
        'session' => 'commander-tasks',
        'user' => 'nckrtl',
        'publish_observer' => true,
    ])->assertCreated();
    $session = HerdrSession::query()->sole();
    $this->observers->published = [];
    $this->inspector->protocol = 20;

    $this->postJson('/api/v1/herdr/sessions/'.$session->id.'/restart')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'herdr.observer_unsupported');

    expect($this->observers->published)
        ->toBeEmpty()
        ->and($session->refresh()->status)
        ->toBe(LifecycleStatus::Failed)
        ->and($session->protocol)
        ->toBe(20)
        ->and($session->observer_status)
        ->toBe('failed');
});

it('lets Commander observe panes on two Nodes without SSH or input capability', function (): void {
    $second = Node::query()->create([
        'name' => 'workhorse',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.22',
        'public_ssh_port' => 22,
        'user' => 'nckrtl',
        'wireguard_ip' => '10.44.0.10',
    ]);
    herdr_sessions_install_tool($second);

    foreach (['beast' => $this->node, 'workhorse' => $second] as $name => $node) {
        $this->postJson('/api/v1/herdr/sessions', [
            'node_id' => $node->id,
            'session' => 'commander-tasks',
            'user' => 'nckrtl',
            'publish_observer' => true,
        ])->assertCreated()->assertJsonPath('data.node', $name);
    }

    $sessions = HerdrSession::query()->orderBy('id')->get();
    expect($sessions)->toHaveCount(2);

    $urls = $sessions->map(function (HerdrSession $session): string {
        return (string) $this->postJson('/api/v1/herdr/sessions/'.$session->id.'/observation-grants', [
            'pane' => 'w1:p1',
            'terminal' => 'term-'.$session->node->name,
            'cols' => 120,
            'rows' => 40,
            'origin' => 'https://tasks.commander.test',
        ])->assertCreated()->json('data.observer_url');
    });

    expect($urls[0])
        ->toStartWith('wss://commander-tasks.herdr.beast.orbit')
        ->and($urls[1])
        ->toStartWith('wss://commander-tasks.herdr.workhorse.orbit')
        ->and($urls->implode(' '))
        ->not->toContain('ssh')
        ->and($urls->implode(' '))
        ->not->toContain('input');
});

function herdr_sessions_install_tool(Node $node): Tool
{
    $manager = $node->toolManagers()->create([
        'name' => ToolManagerName::Brew,
        'status' => LifecycleStatus::Active,
    ]);

    return $node->tools()->create([
        'tool_manager_id' => $manager->id,
        'package' => 'herdr',
        'status' => ToolStatus::Installed,
        'installed_version' => '0.9.0',
    ]);
}
