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
use App\Models\HerdrSession;
use App\Models\Node;
use App\Models\Process;
use Tests\Support\FakeHerdrObserverPublisher;
use Tests\Support\FakeHerdrSessionInspector;

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
            '--observe-listen=127.0.0.1:7411',
            '--observe-mode=read-only',
            '--observe-jwks=https://gateway.orbit/.well-known/jwks.json',
        ])
        ->and($this->runtime->convergedProcessIds)
        ->toBe([$process->id])
        ->and($this->observers->published)
        ->toBe(['commander-tasks']);
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
        'tld' => 'orbit',
        'wireguard_ip' => '10.44.0.9',
    ]);
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

it('lets Commander observe panes on two Nodes without SSH or input capability', function (): void {
    $second = Node::query()->create([
        'name' => 'workhorse',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.22',
        'public_ssh_port' => 22,
        'user' => 'nckrtl',
        'tld' => 'orbit',
        'wireguard_ip' => '10.44.0.10',
    ]);

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
