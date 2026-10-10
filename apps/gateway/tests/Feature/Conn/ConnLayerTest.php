<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Models\Activity;
use App\Models\ConnEnvironment;
use App\Models\ConnPairing;
use App\Models\ConnProfile;
use App\Models\ConnProfileBinding;
use App\Models\Node;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

const CONN_LAYER_SERVER = 'http://10.44.0.30:3773';
const CONN_LAYER_PHONE = '10.44.0.77';

/** @return array<string, mixed> */
function conn_layer_registration(array $overrides = []): array
{
    return [
        'environment_id' => 'env-beast',
        'label' => 'beast',
        'url' => CONN_LAYER_SERVER,
        'admin_session' => 'admin-session-token',
        ...$overrides,
    ];
}

/** Fakes a T3 server that answers registration the way a T3 server's environment API does. */
function conn_layer_fake_server(array $extra = [], array $scopes = ['orchestration:read', 'orchestration:operate', 'terminal:operate', 'review:write', 'relay:read', 'access:read', 'access:write', 'relay:write']): void
{
    Http::preventStrayRequests();
    Http::fake([
        CONN_LAYER_SERVER.'/.well-known/t3/environment' => Http::response([
            'environmentId' => 'env-beast',
            'label' => 'beast',
            'platform' => ['os' => 'linux', 'arch' => 'x64'],
            'serverVersion' => '0.9.1',
            'capabilities' => [],
        ]),
        CONN_LAYER_SERVER.'/api/auth/session' => Http::response([
            'authenticated' => true,
            'auth' => ['policy' => 'remote-reachable', 'bootstrapMethods' => ['one-time-token'], 'sessionMethods' => ['bearer-access-token']],
            'scopes' => $scopes,
            'sessionMethod' => 'bearer-access-token',
            'expiresAt' => Carbon::now()->addDays(30)->toIso8601ZuluString(),
        ]),
        CONN_LAYER_SERVER.'/api/auth/clients' => Http::response([]),
        ...$extra,
    ]);
}

function conn_layer_environment(array $attributes = []): ConnEnvironment
{
    return ConnEnvironment::query()->create([
        'environment_id' => 'env-beast',
        'label' => 'beast',
        'url' => CONN_LAYER_SERVER,
        'admin_session' => 'admin-session-token',
        'admin_session_expires_at' => Carbon::now()->addDays(30),
        'registered_at' => Carbon::now(),
        ...$attributes,
    ]);
}

function conn_layer_node(string $name, string $address, LifecycleStatus $status = LifecycleStatus::Active): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => $status,
        'platform' => 'ios',
        'public_ssh_host' => $address,
        'wireguard_ip' => $address,
    ]);
}

beforeEach(function (): void {
    $this->phone = conn_layer_node('phone', CONN_LAYER_PHONE);
    $this->laptop = conn_layer_node('laptop', '10.44.0.78');
    $this->withServerVariables(['REMOTE_ADDR' => CONN_LAYER_PHONE]);
});

describe('identity', function (): void {
    it('identifies the caller as the active Node that owns its WireGuard address', function (): void {
        $this->getJson('/api/v1/conn/me')
            ->assertOk()
            ->assertJsonPath('data.id', $this->phone->id)
            ->assertJsonPath('data.name', 'phone')
            ->assertJsonPath('data.wireguard_ip', CONN_LAYER_PHONE)
            ->assertJsonPath('data.profile', null);
    });

    it('refuses a caller that is not an active Node', function (string $address): void {
        conn_layer_node('provisioning-phone', '10.44.0.90', LifecycleStatus::Provisioning);

        $this->withServerVariables(['REMOTE_ADDR' => $address])
            ->getJson('/api/v1/conn/me')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'peer.identity_unknown');
    })->with(['unknown address' => '10.44.0.91', 'provisioning Node' => '10.44.0.90', 'public address' => '203.0.113.9']);

    it('answers an unknown caller before it resolves a route binding', function (): void {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->getJson('/api/v1/conn/profiles/999/settings')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'peer.identity_unknown');
    });

    it('needs no node access grant', function (): void {
        expect(Node::query()->find($this->phone->id)?->accessibleNodes()->count())->toBe(0);

        $this->getJson('/api/v1/conn/profiles')->assertOk();
    });
});

describe('profiles', function (): void {
    it('creates and lists profiles that every Node can see', function (): void {
        $this->postJson('/api/v1/conn/profiles', ['name' => 'Nick'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Nick')
            ->assertJsonPath('data.settings_version', 1);
        $this->postJson('/api/v1/conn/profiles', ['name' => 'Nick'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');

        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.78'])
            ->getJson('/api/v1/conn/profiles')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Nick');
    });

    it('binds the calling Node to one profile and keeps the binding', function (): void {
        $nick = ConnProfile::query()->create(['name' => 'Nick', 'settings' => ['workspaces' => []]]);
        $work = ConnProfile::query()->create(['name' => 'Work', 'settings' => ['workspaces' => []]]);

        $this->putJson('/api/v1/conn/me/profile', ['profile_id' => $nick->id])
            ->assertOk()
            ->assertJsonPath('data.profile.name', 'Nick')
            ->assertJsonPath('data.id', $this->phone->id);
        $this->getJson('/api/v1/conn/me')->assertJsonPath('data.profile.id', $nick->id);

        $this->putJson('/api/v1/conn/me/profile', ['profile_id' => $work->id])->assertOk();
        expect(ConnProfileBinding::query()->where('node_id', $this->phone->id)->count())->toBe(1);
        $this->getJson('/api/v1/conn/me')->assertJsonPath('data.profile.name', 'Work');
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.78'])
            ->getJson('/api/v1/conn/me')->assertJsonPath('data.profile', null);

        $this->putJson('/api/v1/conn/me/profile', ['profile_id' => 999])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');
    });
});

describe('profile settings', function (): void {
    $workspace = [
        'id' => 'ws-1',
        'name' => 'Orbit',
        'color' => 'blue',
        'icon' => 'rocket',
        'projectRefs' => ['env-beast:project-1', 'env-mini:project-2'],
        'projectKeys' => ['orbit'],
    ];

    it('replaces the settings document and increases its version', function () use ($workspace): void {
        $profile = ConnProfile::query()->create(['name' => 'Nick', 'settings' => ['workspaces' => []]]);

        $this->getJson("/api/v1/conn/profiles/{$profile->id}/settings")
            ->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.settings', ['workspaces' => []]);

        $this->putJson("/api/v1/conn/profiles/{$profile->id}/settings", [
            'version' => 1,
            'settings' => ['workspaces' => [$workspace, ['id' => 'ws-2', 'name' => 'Home', 'color' => 'red', 'icon' => null, 'image' => 'data:image/png;base64,iVBORw0KGgo=', 'projectKeys' => ['home']]]],
        ])
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.settings.workspaces.0', $workspace)
            ->assertJsonPath('data.settings.workspaces.1.image', 'data:image/png;base64,iVBORw0KGgo=')
            ->assertJsonMissingPath('data.settings.workspaces.1.projectRefs');
        expect(Activity::query()->where('command', 'conn:profile:settings:update')->sole()->properties?->toArray()['input'])
            ->toBe(['version' => 1]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.78'])
            ->getJson("/api/v1/conn/profiles/{$profile->id}/settings")
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.settings.workspaces.0.projectRefs', ['env-beast:project-1', 'env-mini:project-2']);
    });

    it('refuses a write from a stale version and keeps the newer document', function () use ($workspace): void {
        $profile = ConnProfile::query()->create(['name' => 'Nick', 'settings' => ['workspaces' => []]]);
        $this->putJson("/api/v1/conn/profiles/{$profile->id}/settings", ['version' => 1, 'settings' => ['workspaces' => [$workspace]]])
            ->assertOk();

        $this->putJson("/api/v1/conn/profiles/{$profile->id}/settings", ['version' => 1, 'settings' => ['workspaces' => []]])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'conn.settings_version_conflict')
            ->assertJsonPath('error.details.current_version', 2);

        expect($profile->refresh()->settings['workspaces'])->toHaveCount(1)
            ->and($profile->settings_version)->toBe(2);
    });

    it('refuses settings outside the workspace contract', function (array $settings, string $field): void {
        $profile = ConnProfile::query()->create(['name' => 'Nick', 'settings' => ['workspaces' => []]]);

        $this->putJson("/api/v1/conn/profiles/{$profile->id}/settings", ['version' => 1, 'settings' => $settings])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonValidationErrors([$field], 'error.details');
    })->with([
        'unknown top-level key' => [['workspaces' => [], 'theme' => 'dark'], 'settings'],
        'project ref without environment' => [['workspaces' => [['id' => 'ws-1', 'name' => 'A', 'color' => 'blue', 'icon' => null, 'projectRefs' => ['project-1']]]], 'settings.workspaces.0.projectRefs.0'],
        'unknown workspace key' => [['workspaces' => [['id' => 'ws-1', 'name' => 'A', 'color' => 'blue', 'icon' => null, 'projectRefs' => [], 'secret' => 'x']]], 'settings.workspaces.0'],
        'duplicate workspace id' => [['workspaces' => [
            ['id' => 'ws-1', 'name' => 'A', 'color' => 'blue', 'icon' => null, 'projectRefs' => []],
            ['id' => 'ws-1', 'name' => 'B', 'color' => 'blue', 'icon' => null, 'projectRefs' => []],
        ]], 'settings.workspaces.0.id'],
        'image that is not a picture' => [['workspaces' => [['id' => 'ws-1', 'name' => 'A', 'color' => 'blue', 'icon' => null, 'image' => 'https://example.com/a.png', 'projectRefs' => []]]], 'settings.workspaces.0.image'],
    ]);
});

describe('environment registration', function (): void {
    it('checks the admin session with the T3 server and stores it encrypted', function (): void {
        conn_layer_fake_server();

        $this->postJson('/api/v1/conn/environments', conn_layer_registration())
            ->assertOk()
            ->assertJsonPath('data.environment_id', 'env-beast')
            ->assertJsonPath('data.url', CONN_LAYER_SERVER)
            ->assertJsonPath('data.server_version', '0.9.1')
            ->assertJsonPath('data.registered_by', 'phone')
            ->assertJsonPath('data.status', 'registered')
            ->assertJsonMissingPath('data.admin_session');

        Http::assertSent(static fn (HttpRequest $request): bool => $request->url() === CONN_LAYER_SERVER.'/api/auth/session'
            && $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer admin-session-token'));

        $environment = ConnEnvironment::query()->sole();
        $stored = DB::table('conn_environments')->value('admin_session');
        expect($environment->admin_session)->toBe('admin-session-token')
            ->and($stored)->not->toContain('admin-session-token')
            ->and($environment->admin_session_expires_at->isAfter(Carbon::now()->addDays(29)))->toBeTrue()
            ->and(Activity::query()->where('command', 'conn:environment:register')->sole()->properties?->toArray()['input'])
            ->toBe(['environment_id' => 'env-beast', 'label' => 'beast', 'url' => CONN_LAYER_SERVER])
            ->and(json_encode(Activity::query()->sole()->properties))->not->toContain('admin-session-token');

        $this->getJson('/api/v1/conn/environments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.label', 'beast');
    });

    it('replaces the session on re-registration and revokes the earlier Gateway sessions', function (): void {
        conn_layer_fake_server([
            CONN_LAYER_SERVER.'/api/auth/clients/revoke' => Http::response(['revoked' => true]),
            CONN_LAYER_SERVER.'/api/auth/clients' => Http::response([
                ['sessionId' => 'new', 'client' => ['label' => 'Orbit Gateway', 'deviceType' => 'bot'], 'current' => true],
                ['sessionId' => 'old', 'client' => ['label' => 'Orbit Gateway', 'deviceType' => 'bot'], 'current' => false],
                ['sessionId' => 'phone', 'client' => ['label' => 'iPhone', 'deviceType' => 'mobile'], 'current' => false],
            ]),
        ]);
        conn_layer_environment(['label' => 'old label', 'admin_session' => 'old-session']);

        $this->postJson('/api/v1/conn/environments', conn_layer_registration())->assertOk()->assertJsonPath('data.label', 'beast');

        expect(ConnEnvironment::query()->count())->toBe(1)
            ->and(ConnEnvironment::query()->sole()->admin_session)->toBe('admin-session-token');
        Http::assertSent(static fn (HttpRequest $request): bool => $request->url() === CONN_LAYER_SERVER.'/api/auth/clients/revoke'
            && $request['sessionId'] === 'old'
            && $request->hasHeader('Authorization', 'Bearer admin-session-token'));
        Http::assertNotSent(static fn (HttpRequest $request): bool => $request->url() === CONN_LAYER_SERVER.'/api/auth/clients/revoke'
            && in_array($request['sessionId'], ['new', 'phone'], true));
    });

    it('refuses a URL that serves another environment before it sends the session', function (): void {
        conn_layer_fake_server();

        $this->postJson('/api/v1/conn/environments', conn_layer_registration(['environment_id' => 'env-other']))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'conn.environment_mismatch');

        Http::assertNotSent(static fn (HttpRequest $request): bool => str_ends_with($request->url(), '/api/auth/session'));
        expect(ConnEnvironment::query()->count())->toBe(0);
    });

    it('refuses a session that is not an admin session', function (): void {
        conn_layer_fake_server(scopes: ['orchestration:read', 'orchestration:operate', 'terminal:operate', 'review:write', 'relay:read']);

        $this->postJson('/api/v1/conn/environments', conn_layer_registration())
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'conn.admin_scope_missing');
        expect(ConnEnvironment::query()->count())->toBe(0);
    });

    it('reports a session the T3 server does not accept', function (): void {
        conn_layer_fake_server([
            CONN_LAYER_SERVER.'/api/auth/session' => Http::response([
                'authenticated' => false,
                'auth' => ['policy' => 'remote-reachable', 'bootstrapMethods' => ['one-time-token'], 'sessionMethods' => ['bearer-access-token']],
            ]),
        ]);

        $this->postJson('/api/v1/conn/environments', conn_layer_registration())
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'conn.session_rejected')
            ->assertJsonPath('error.details.operation', 'session');
        expect(ConnEnvironment::query()->count())->toBe(0);
    });

    it('refuses a registration without an admin session', function (): void {
        $this->postJson('/api/v1/conn/environments', conn_layer_registration(['admin_session' => 'two words']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['admin_session'], 'error.details');
    });
});

describe('pairing', function (): void {
    it('mints a pairing link for the calling Node and records it', function (): void {
        Http::preventStrayRequests();
        Http::fake([
            CONN_LAYER_SERVER.'/api/auth/pairing-token' => Http::response([
                'id' => 'link-1',
                'credential' => 'device-pairing-token',
                'label' => 'ignored',
                'expiresAt' => '2026-10-08T12:05:00.000Z',
            ]),
        ]);
        conn_layer_environment();

        $response = $this->postJson('/api/v1/conn/environments/env-beast/pairings')
            ->assertCreated()
            ->assertJsonPath('data.pairing_url', CONN_LAYER_SERVER.'/pair#token=device-pairing-token')
            ->assertJsonPath('data.pairing.environment_id', 'env-beast')
            ->assertJsonPath('data.pairing.node_name', 'phone')
            ->assertJsonPath('data.pairing.revoked_at', null);

        $pairing = ConnPairing::query()->sole();
        expect($pairing->pairing_link_id)->toBe('link-1')
            ->and($pairing->client_label)->toStartWith('phone via Orbit ')
            ->and($response->json('data.pairing.client_label'))->toBe($pairing->client_label);
        Http::assertSent(static fn (HttpRequest $request): bool => $request->url() === CONN_LAYER_SERVER.'/api/auth/pairing-token'
            && $request->hasHeader('Authorization', 'Bearer admin-session-token')
            && $request['label'] === $pairing->client_label
            && ! isset($request['scopes']));

        $this->getJson('/api/v1/conn/environments/env-beast/pairings')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('data.0.pairing_link_id')
            ->assertJsonPath('data.0.client_label', $pairing->client_label);
        expect(Activity::query()->where('command', 'conn:pairing:create')->value('caller_ip'))->toBe(CONN_LAYER_PHONE);
    });

    it('refuses to mint with an expired admin session', function (): void {
        Http::preventStrayRequests();
        conn_layer_environment(['admin_session_expires_at' => Carbon::now()->subMinute()]);

        $this->postJson('/api/v1/conn/environments/env-beast/pairings')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'conn.session_expired');
        $this->getJson('/api/v1/conn/environments')->assertJsonPath('data.0.status', 'session_expired');
    });

    it('reports an unreachable T3 server', function (): void {
        Http::fake([CONN_LAYER_SERVER.'/*' => Http::failedConnection()]);
        conn_layer_environment();

        $this->postJson('/api/v1/conn/environments/env-beast/pairings')
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'conn.server_unreachable');
        expect(ConnPairing::query()->count())->toBe(0);
    });

    it('answers 404 for an environment that never registered', function (): void {
        $this->postJson('/api/v1/conn/environments/env-missing/pairings')->assertNotFound();
    });
});

describe('revoke', function (): void {
    it("revokes the Node's sessions and unused links on the server", function (): void {
        Http::preventStrayRequests();
        $environment = conn_layer_environment();
        $phone = $this->phone;
        $laptop = $this->laptop;
        foreach ([[$phone, 'link-1', 'phone via Orbit a'], [$phone, 'link-2', 'phone via Orbit b'], [$laptop, 'link-3', 'laptop via Orbit c']] as [$node, $link, $label]) {
            ConnPairing::query()->create([
                'node_id' => $node->id,
                'conn_environment_id' => $environment->id,
                'pairing_link_id' => $link,
                'client_label' => $label,
                'expires_at' => Carbon::now()->addMinutes(5),
            ]);
        }
        Http::fake([
            CONN_LAYER_SERVER.'/api/auth/clients/revoke' => Http::response(['revoked' => true]),
            CONN_LAYER_SERVER.'/api/auth/pairing-links/revoke' => Http::response(['revoked' => false]),
            CONN_LAYER_SERVER.'/api/auth/clients' => Http::response([
                ['sessionId' => 'gateway', 'client' => ['label' => 'Orbit Gateway', 'deviceType' => 'bot'], 'current' => true],
                ['sessionId' => 'phone-session', 'client' => ['label' => 'phone via Orbit a', 'deviceType' => 'mobile'], 'current' => false],
                ['sessionId' => 'laptop-session', 'client' => ['label' => 'laptop via Orbit c', 'deviceType' => 'desktop'], 'current' => false],
            ]),
        ]);

        $this->postJson('/api/v1/conn/environments/env-beast/pairings/revoke')
            ->assertOk()
            ->assertJsonPath('data.node_id', $phone->id)
            ->assertJsonPath('data.revoked_sessions', 1)
            ->assertJsonCount(2, 'data.pairings');

        Http::assertSent(static fn (HttpRequest $request): bool => $request->url() === CONN_LAYER_SERVER.'/api/auth/clients/revoke'
            && $request['sessionId'] === 'phone-session'
            && $request->hasHeader('Authorization', 'Bearer admin-session-token'));
        Http::assertNotSent(static fn (HttpRequest $request): bool => $request->url() === CONN_LAYER_SERVER.'/api/auth/clients/revoke'
            && $request['sessionId'] !== 'phone-session');
        Http::assertSent(static fn (HttpRequest $request): bool => $request->url() === CONN_LAYER_SERVER.'/api/auth/pairing-links/revoke' && $request['id'] === 'link-2');
        expect(ConnPairing::query()->whereNotNull('revoked_at')->pluck('pairing_link_id')->all())->toBe(['link-1', 'link-2']);

        $this->postJson('/api/v1/conn/environments/env-beast/pairings/revoke', ['node_id' => $laptop->id])
            ->assertOk()
            ->assertJsonPath('data.revoked_sessions', 1);
        Http::assertSent(static fn (HttpRequest $request): bool => $request->url() === CONN_LAYER_SERVER.'/api/auth/clients/revoke' && $request['sessionId'] === 'laptop-session');
    });

    it('calls nothing when the Node holds no pairing on the server', function (): void {
        Http::preventStrayRequests();
        conn_layer_environment();

        $this->postJson('/api/v1/conn/environments/env-beast/pairings/revoke')
            ->assertOk()
            ->assertJsonPath('data.revoked_sessions', 0)
            ->assertJsonPath('data.pairings', []);
        Http::assertNothingSent();
    });
});
